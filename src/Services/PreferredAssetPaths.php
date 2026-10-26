<?php

namespace MotionWork\StatamicAiAssistant\Services;

/**
 * Mutable queue of {container, path} pairs the asset resolver should consume
 * before falling back to random container assets. Mutability is intentional:
 * the resolver cycles paths as fields are filled (taken entries rotate to the
 * back), and each generation job has its own instance, so there is no
 * shared-state hazard between concurrent queue workers.
 */
class PreferredAssetPaths
{
    /** @var array<int, array{container: string, path: string}> */
    private array $entries;

    /** @param array<int, array{container: string, path: string}> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = array_values($entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Append a {container, path} pair. Used by the save_remote_image tool to
     * register an image it just downloaded so the asset resolver assigns it to
     * one of the entry's asset fields (matched by container handle).
     */
    public function add(string $container, string $path): void
    {
        $this->entries[] = ['container' => $container, 'path' => $path];
    }

    /**
     * Remaining {container, path} pairs after any takeForContainer calls.
     * Used to persist shared preferred-asset state between chained generation jobs.
     *
     * @return array<int, array{container: string, path: string}>
     */
    public function remainingEntries(): array
    {
        return array_values($this->entries);
    }

    /**
     * Combine multiple queues, preserving order. Used when several upstream
     * sources (e.g. one downloader call per fetched URL in a prompt) each
     * produce their own queue and the resolver should see all of them.
     */
    public static function merge(self ...$instances): self
    {
        $combined = [];
        foreach ($instances as $instance) {
            foreach ($instance->entries as $entry) {
                $combined[] = $entry;
            }
        }

        return new self($combined);
    }

    /**
     * Take up to $count paths whose container matches the field's container.
     * Returns the relative paths (no container prefix) — the resolver assigns
     * them straight to the `assets` field value.
     *
     * Taken entries ROTATE to the back of the queue instead of being deleted:
     * a single referenced image must be able to serve every asset field ("use
     * this image everywhere" — hero AND seo_image), and when a batch has more
     * fields/entries than preferred images, cycling through user-chosen imagery
     * beats falling back to random container assets. Distribution still works:
     * consecutive takes round-robin through the pool. Within ONE take each
     * entry is used at most once (no duplicates inside a single gallery).
     *
     * @return array<int, string>
     */
    public function takeForContainer(string $containerHandle, int $count): array
    {
        $taken = [];
        $rotated = [];

        foreach ($this->entries as $idx => $entry) {
            if (count($taken) >= $count) {
                break;
            }
            if (($entry['container'] ?? null) === $containerHandle) {
                $taken[] = (string) $entry['path'];
                $rotated[] = $entry;
                unset($this->entries[$idx]);
            }
        }

        $this->entries = array_values(array_merge($this->entries, $rotated));

        return $taken;
    }
}
