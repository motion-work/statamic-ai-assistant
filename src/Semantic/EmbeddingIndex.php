<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

/**
 * The embedding vectors of one site, flat-file (the addon has no database):
 *
 *   <root>/<site>/manifest.json  {model, dimensions, updated_at, entries: [{id, collection, hash}]}
 *   <root>/<site>/vectors.bin    one row per manifest entry, in the same order:
 *                                `dimensions` little-endian float32, unit length
 *
 * Vectors are stored normalised, so cosine similarity is a dot product.
 * Writes rewrite both files under an exclusive lock (temp file + rename), the
 * manifest last, so a reader sees the old or the new index, never a mix. An
 * index built with another model or dimensions counts as empty.
 */
final class EmbeddingIndex
{
    private const MANIFEST = 'manifest.json';

    private const VECTORS = 'vectors.bin';

    public function __construct(private readonly string $site) {}

    public static function root(): string
    {
        $path = trim((string) config('statamic-ai-assistant.semantic_search.path'));

        return rtrim($path !== '' ? $path : storage_path('app/statamic-ai-assistant/embeddings'), '/');
    }

    public function site(): string
    {
        return $this->site;
    }

    public function exists(): bool
    {
        return is_file($this->path(self::MANIFEST));
    }

    /**
     * The manifest, or null when the index was never built.
     *
     * @return array{model: string, dimensions: int, updated_at: ?string, entries: list<array{id: string, collection: string, hash: string}>}|null
     */
    public function manifest(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path(self::MANIFEST)), true);
        if (! is_array($data) || ! is_array($data['entries'] ?? null)) {
            throw new \RuntimeException('Unreadable embedding index manifest for site '.$this->site);
        }

        return [
            'model' => (string) ($data['model'] ?? ''),
            'dimensions' => (int) ($data['dimensions'] ?? 0),
            'updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            'entries' => array_values($data['entries']),
        ];
    }

    /** Whether the stored vectors come from this model and size. */
    public function matches(string $model, int $dimensions): bool
    {
        $manifest = $this->manifest();

        return $manifest !== null && $manifest['model'] === $model && $manifest['dimensions'] === $dimensions;
    }

    /**
     * Stored text hashes by entry id, empty for another model or size.
     *
     * @return array<string, string>
     */
    public function hashes(string $model, int $dimensions): array
    {
        if (! $this->matches($model, $dimensions)) {
            return [];
        }

        return array_column($this->manifest()['entries'], 'hash', 'id');
    }

    /**
     * The best matches for a query vector, highest similarity first.
     *
     * @param  list<float>  $query
     * @return list<array{id: string, score: float}>
     */
    public function nearest(array $query, ?string $collection, int $limit, float $minSimilarity): array
    {
        $manifest = $this->manifest();
        $dimensions = $manifest['dimensions'] ?? 0;
        $vectors = $this->vectorBytes(count($manifest['entries'] ?? []), $dimensions);
        $queryRow = array_combine(range(1, $dimensions), Vector::normalize($query));

        $scores = [];
        foreach ($manifest['entries'] ?? [] as $row => $meta) {
            if ($collection !== null && $collection !== '' && $meta['collection'] !== $collection) {
                continue;
            }
            $score = Vector::dot($queryRow, unpack('g*', substr($vectors, $row * $dimensions * 4, $dimensions * 4)));
            if ($score >= $minSimilarity) {
                $scores[$meta['id']] = $score;
            }
        }
        arsort($scores);

        return $this->topRows($scores, $limit);
    }

    /**
     * Add or replace vectors. Starts a fresh index when the stored one was
     * built with another model or size.
     *
     * @param  array<string, array{collection: string, hash: string, vector: list<float>}>  $items
     */
    public function upsert(array $items, string $model, int $dimensions): void
    {
        $this->locked(function () use ($items, $model, $dimensions) {
            $rows = $this->storedRows($model, $dimensions);
            foreach ($items as $id => $item) {
                $rows[(string) $id] = [
                    'collection' => $item['collection'],
                    'hash' => $item['hash'],
                    'bytes' => pack('g*', ...Vector::normalize($item['vector'])),
                ];
            }
            $this->write($rows, $model, $dimensions);
        });
    }

    /**
     * @param  list<string>  $ids
     */
    public function remove(array $ids): void
    {
        if ($ids === [] || ! $this->exists()) {
            return;
        }

        $this->locked(function () use ($ids) {
            $manifest = $this->manifest();
            $rows = array_diff_key($this->storedRows($manifest['model'], $manifest['dimensions']), array_flip($ids));
            $this->write($rows, $manifest['model'], $manifest['dimensions']);
        });
    }

    public function clear(): void
    {
        foreach ([self::MANIFEST, self::VECTORS, '.lock'] as $file) {
            @unlink($this->path($file));
        }
        @rmdir($this->dir());
    }

    /**
     * @param  array<string, float>  $scores  Sorted, highest first.
     * @return list<array{id: string, score: float}>
     */
    private function topRows(array $scores, int $limit): array
    {
        $rows = [];
        foreach (array_slice($scores, 0, max(1, $limit), true) as $id => $score) {
            $rows[] = ['id' => (string) $id, 'score' => $score];
        }

        return $rows;
    }

    /**
     * The stored rows of a matching index, keyed by entry id.
     *
     * @return array<string, array{collection: string, hash: string, bytes: string}>
     */
    private function storedRows(string $model, int $dimensions): array
    {
        if (! $this->matches($model, $dimensions)) {
            return [];
        }

        $entries = $this->manifest()['entries'];
        $vectors = $this->vectorBytes(count($entries), $dimensions);
        $rows = [];
        foreach ($entries as $row => $meta) {
            $rows[(string) $meta['id']] = [
                'collection' => (string) $meta['collection'],
                'hash' => (string) $meta['hash'],
                'bytes' => substr($vectors, $row * $dimensions * 4, $dimensions * 4),
            ];
        }

        return $rows;
    }

    /** The vector file, checked against the manifest's row count. */
    private function vectorBytes(int $rows, int $dimensions): string
    {
        $bytes = is_file($this->path(self::VECTORS)) ? (string) file_get_contents($this->path(self::VECTORS)) : '';

        if ($dimensions < 1 || strlen($bytes) !== $rows * $dimensions * 4) {
            throw new \RuntimeException('Embedding index vectors do not match the manifest for site '.$this->site);
        }

        return $bytes;
    }

    /**
     * @param  array<string, array{collection: string, hash: string, bytes: string}>  $rows
     */
    private function write(array $rows, string $model, int $dimensions): void
    {
        $entries = [];
        foreach ($rows as $id => $row) {
            $entries[] = ['id' => (string) $id, 'collection' => $row['collection'], 'hash' => $row['hash']];
        }

        $this->atomicWrite(self::VECTORS, implode('', array_column($rows, 'bytes')));
        $this->atomicWrite(self::MANIFEST, (string) json_encode([
            'model' => $model,
            'dimensions' => $dimensions,
            'updated_at' => now()->toIso8601String(),
            'entries' => $entries,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $temp = $this->path($file.'.'.bin2hex(random_bytes(4)).'.tmp');

        if (file_put_contents($temp, $contents) === false || ! rename($temp, $this->path($file))) {
            @unlink($temp);

            throw new \RuntimeException('Could not write the embedding index for site '.$this->site);
        }
    }

    private function locked(callable $fn): void
    {
        if (! is_dir($this->dir()) && ! mkdir($this->dir(), 0775, true) && ! is_dir($this->dir())) {
            throw new \RuntimeException('Could not create '.$this->dir());
        }

        $handle = fopen($this->path('.lock'), 'c');
        if ($handle === false) {
            throw new \RuntimeException('Could not lock the embedding index for site '.$this->site);
        }

        try {
            flock($handle, LOCK_EX);
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function dir(): string
    {
        return self::root().'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $this->site);
    }

    private function path(string $file): string
    {
        return $this->dir().'/'.$file;
    }
}
