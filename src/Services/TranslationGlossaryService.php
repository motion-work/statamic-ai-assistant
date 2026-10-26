<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;

/**
 * CP-managed DeepL glossary: editors maintain one table of terms with a column
 * per site language; on save the table is pushed to DeepL as ONE multilingual
 * glossary (v3) with a dictionary per ordered language pair. The stored
 * glossary id is then attached automatically to every translation request in
 * DeeplService::translateBatch, so page/bulk/field/Bard/navigation translations
 * all honour it.
 *
 * Storage (versionable YAML, default content/statamic-ai-assistant/translation-glossary.yaml):
 *   glossary_id: 8d1f2e…            # DeepL glossary id from the last successful sync
 *   entries:
 *     - id: 1c9e…                   # local row id (uuid)
 *       terms:
 *         de: Zimmer
 *         en: Room
 *         fr: Chambre
 */
class TranslationGlossaryService
{
    /** @var array{glossary_id: ?string, entries: array<int, array{id: string, terms: array<string, string>}>}|null */
    private ?array $cache = null;

    public function __construct(private DeeplService $deepl) {}

    public function storagePath(): string
    {
        $path = config('statamic-ai-assistant.translation_glossary_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/translation-glossary.yaml');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * Base language codes (de, en, fr, …) of all configured sites, in site order.
     * These are the term columns in the CP table and the dictionary languages on DeepL.
     *
     * @return array<int, string>
     */
    public function languages(): array
    {
        $langs = [];

        foreach (Site::all() as $site) {
            $mapped = $this->deepl->mapLanguage((string) $site->locale());
            $base = strtolower((string) preg_replace('/[-_].*$/', '', $mapped));

            if ($base !== '' && ! in_array($base, $langs, true)) {
                $langs[] = $base;
            }
        }

        return $langs;
    }

    /**
     * @return array<int, array{id: string, terms: array<string, string>}>
     */
    public function entries(): array
    {
        return $this->data()['entries'];
    }

    public function glossaryId(): ?string
    {
        return $this->data()['glossary_id'];
    }

    /**
     * Persist the submitted entry rows (normalized) without touching DeepL.
     * Rows with no non-empty term at all are dropped.
     *
     * @param  array<int, mixed>  $entries
     * @return array<int, array{id: string, terms: array<string, string>}>
     */
    public function save(array $entries): array
    {
        $clean = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $terms = [];

            foreach ((array) ($entry['terms'] ?? []) as $lang => $term) {
                if (! is_string($lang) || ! is_string($term)) {
                    continue;
                }

                $lang = strtolower(trim($lang));
                // DeepL glossary terms must be single-line; tabs are the TSV separator.
                $term = trim((string) preg_replace('/[\t\r\n]+/', ' ', $term));

                if ($lang !== '' && $term !== '') {
                    $terms[$lang] = $term;
                }
            }

            if ($terms === []) {
                continue;
            }

            $id = isset($entry['id']) && is_string($entry['id']) && trim($entry['id']) !== ''
                ? trim($entry['id'])
                : Str::uuid()->toString();

            $clean[] = ['id' => $id, 'terms' => $terms];
        }

        $data = $this->data();
        $data['entries'] = $clean;

        $this->write($data);

        return $clean;
    }

    /**
     * Push the stored entries to DeepL (recreate the multilingual glossary) and
     * persist the new glossary id. Returns warnings instead of throwing so a
     * DeepL hiccup never loses the locally saved table.
     *
     * @return array<int, string> warnings
     */
    public function sync(): array
    {
        $data = $this->data();
        $dictionaries = $this->buildDictionaries($data['entries']);
        $warnings = [];

        try {
            if ($dictionaries === []) {
                if ($data['glossary_id'] !== null) {
                    $this->deepl->deleteGlossaryOnDeepL($data['glossary_id']);
                }
                $data['glossary_id'] = null;
            } else {
                $data['glossary_id'] = $this->deepl->createGlossaryOnDeepL(
                    $this->glossaryName(),
                    $dictionaries,
                    $data['glossary_id'],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[deepl-glossary] sync failed', ['message' => $e->getMessage()]);
            $warnings[] = __('The glossary was saved locally but could not be synced to DeepL: :error', ['error' => $e->getMessage()]);

            return $warnings;
        }

        $this->write($data);

        return $warnings;
    }

    /**
     * Glossary id to attach when translating $sourceBase → $targetBase — only
     * when the synced glossary actually has terms for that pair.
     */
    public function glossaryIdFor(string $sourceBase, string $targetBase): ?string
    {
        $data = $this->data();

        if ($data['glossary_id'] === null || $sourceBase === $targetBase) {
            return null;
        }

        foreach ($data['entries'] as $entry) {
            $sourceTerm = $entry['terms'][$sourceBase] ?? '';
            $targetTerm = $entry['terms'][$targetBase] ?? '';

            if ($sourceTerm !== '' && $targetTerm !== '') {
                return $data['glossary_id'];
            }
        }

        return null;
    }

    /**
     * Source-language terms that have a target translation for the given pair.
     * Used to detect which text segments actually carry a glossary term so the
     * classic (hard-glossary) model can be used only where it matters.
     *
     * @return array<int, string>
     */
    public function sourceTermsFor(string $sourceBase, string $targetBase): array
    {
        if ($sourceBase === $targetBase) {
            return [];
        }

        $terms = [];

        foreach ($this->data()['entries'] as $entry) {
            $sourceTerm = $entry['terms'][$sourceBase] ?? '';
            $targetTerm = $entry['terms'][$targetBase] ?? '';

            if ($sourceTerm !== '' && $targetTerm !== '') {
                $terms[] = $sourceTerm;
            }
        }

        return $terms;
    }

    /**
     * One dictionary per ordered language pair that has at least one complete row.
     * Duplicate source terms collapse (last row wins) — DeepL rejects duplicates.
     *
     * @param  array<int, array{id: string, terms: array<string, string>}>  $entries
     * @return array<int, array{source_lang: string, target_lang: string, entries: array<string, string>}>
     */
    public function buildDictionaries(array $entries): array
    {
        $languages = [];

        foreach ($entries as $entry) {
            foreach (array_keys($entry['terms']) as $lang) {
                if (! in_array($lang, $languages, true)) {
                    $languages[] = $lang;
                }
            }
        }

        $dictionaries = [];

        foreach ($languages as $source) {
            foreach ($languages as $target) {
                if ($source === $target) {
                    continue;
                }

                $pairs = [];

                foreach ($entries as $entry) {
                    $sourceTerm = $entry['terms'][$source] ?? '';
                    $targetTerm = $entry['terms'][$target] ?? '';

                    if ($sourceTerm !== '' && $targetTerm !== '') {
                        $pairs[$sourceTerm] = $targetTerm;
                    }
                }

                if ($pairs !== []) {
                    $dictionaries[] = [
                        'source_lang' => $source,
                        'target_lang' => $target,
                        'entries' => $pairs,
                    ];
                }
            }
        }

        return $dictionaries;
    }

    protected function glossaryName(): string
    {
        return 'Statamic CMS — '.(string) config('app.name', 'site');
    }

    /**
     * @return array{glossary_id: ?string, entries: array<int, array{id: string, terms: array<string, string>}>}
     */
    private function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->storagePath();

        if (! is_file($path)) {
            return $this->cache = ['glossary_id' => null, 'entries' => []];
        }

        try {
            $raw = (string) file_get_contents($path);
            $parsed = $raw !== '' ? YAML::parse($raw) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to parse translation-glossary.yaml', ['error' => $e->getMessage()]);

            return $this->cache = ['glossary_id' => null, 'entries' => []];
        }

        $glossaryId = isset($parsed['glossary_id']) && is_string($parsed['glossary_id']) && trim($parsed['glossary_id']) !== ''
            ? trim($parsed['glossary_id'])
            : null;

        $entries = [];

        foreach ((array) ($parsed['entries'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['terms']) || ! is_array($entry['terms'])) {
                continue;
            }

            $terms = [];

            foreach ($entry['terms'] as $lang => $term) {
                if (is_string($lang) && is_string($term) && trim($term) !== '') {
                    $terms[strtolower(trim($lang))] = trim($term);
                }
            }

            if ($terms === []) {
                continue;
            }

            $entries[] = [
                'id' => isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : Str::uuid()->toString(),
                'terms' => $terms,
            ];
        }

        return $this->cache = ['glossary_id' => $glossaryId, 'entries' => $entries];
    }

    /**
     * @param  array{glossary_id: ?string, entries: array<int, array{id: string, terms: array<string, string>}>}  $data
     */
    private function write(array $data): void
    {
        $path = $this->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $document = array_filter([
            'glossary_id' => $data['glossary_id'],
            'entries' => $data['entries'],
        ], fn ($v) => $v !== null);

        file_put_contents($path, YAML::dump($document));

        $this->cache = $data;
    }
}
