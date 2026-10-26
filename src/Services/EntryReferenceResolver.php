<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class EntryReferenceResolver
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const ENTRY_LINK_PATTERN = '/^entry::([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    /** @var array<string, bool> IDs currently being translated (circular reference protection) */
    private array $inProgress = [];

    /** @var array<string, string> Cache of already-resolved origin->target ID mappings */
    private array $resolvedCache = [];

    private ?EntryTranslator $entryTranslator = null;

    private bool $force;

    public function __construct(bool $force = false)
    {
        $this->force = $force;
    }

    public function setEntryTranslator(EntryTranslator $translator): void
    {
        $this->entryTranslator = $translator;
    }

    /**
     * Target id reserved or resolved for this origin→site pair (used so the outer
     * translateEntry() reuses the entry already saved during nested reference resolution).
     */
    public function getCachedTargetId(string $originId, string $targetSite): ?string
    {
        $cacheKey = $originId.':'.$targetSite;

        return $this->resolvedCache[$cacheKey] ?? null;
    }

    /**
     * Resolve a value that may contain entry references.
     * Handles: "entry::UUID", bare UUID strings, and arrays of UUIDs.
     *
     * @return string|array<mixed>|mixed
     */
    public function resolve(mixed $value, string $targetSite, int $currentDepth, int $maxDepth): mixed
    {
        if (is_array($value)) {
            return $this->resolveArray($value, $targetSite, $currentDepth, $maxDepth);
        }

        if (! is_string($value)) {
            return $value;
        }

        if (preg_match(self::ENTRY_LINK_PATTERN, $value, $matches)) {
            $resolvedId = $this->resolveEntryId($matches[1], $targetSite, $currentDepth, $maxDepth);

            return 'entry::'.$resolvedId;
        }

        if (preg_match(self::UUID_PATTERN, $value) && Entry::find($value)) {
            return $this->resolveEntryId($value, $targetSite, $currentDepth, $maxDepth);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function resolveArray(array $values, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        return array_map(
            fn ($value) => $this->resolve($value, $targetSite, $currentDepth, $maxDepth),
            $values,
        );
    }

    private function resolveEntryId(string $originId, string $targetSite, int $currentDepth, int $maxDepth): string
    {
        $cacheKey = $originId.':'.$targetSite;

        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $existingTarget = $this->findLocalizedEntry($originId, $targetSite);

        if ($existingTarget && ! $this->force) {
            $this->resolvedCache[$cacheKey] = $existingTarget->id();

            return $existingTarget->id();
        }

        if ($currentDepth >= $maxDepth) {
            return $originId;
        }

        $originEntry = Entry::find($originId);
        if (! $originEntry) {
            return $originId;
        }

        $collection = Collection::findByHandle($originEntry->collectionHandle());
        if (! $collection || ! in_array($targetSite, $collection->sites()->all())) {
            return $originId;
        }

        if (! $this->entryTranslator) {
            return $originId;
        }

        // Reserve the target id before translateEntry() runs so nested resolveEntryId()
        // calls for the same origin (e.g. projects → magazine → projects) resolve to
        // the English UUID immediately instead of hitting inProgress and falling back
        // to the source id, or waiting on Stache to see a not-yet-saved localization.
        $this->resolvedCache[$cacheKey] = $existingTarget
            ? $existingTarget->id()
            : (string) Str::uuid();

        $this->inProgress[$originId] = true;

        try {
            $targetForTranslator = $existingTarget;
            if (! $targetForTranslator) {
                $targetForTranslator = Entry::make()
                    ->id($this->resolvedCache[$cacheKey])
                    ->collection($originEntry->collectionHandle())
                    ->blueprint($originEntry->blueprint()->handle())
                    ->locale($targetSite)
                    ->origin($originEntry->id());
            }

            $targetEntry = $this->entryTranslator->translateEntry(
                $originEntry,
                $targetSite,
                $targetForTranslator,
                $currentDepth + 1,
                $maxDepth,
            );

            $resolvedId = $targetEntry->id();
            $this->resolvedCache[$cacheKey] = $resolvedId;

            return $resolvedId;
        } catch (\Throwable $e) {
            unset($this->resolvedCache[$cacheKey]);

            throw $e;
        } finally {
            unset($this->inProgress[$originId]);
        }
    }

    private function findLocalizedEntry(string $originId, string $targetSite): mixed
    {
        return Entry::query()
            ->where('origin', $originId)
            ->where('site', $targetSite)
            ->first();
    }

    public function resetState(): void
    {
        $this->inProgress = [];
        $this->resolvedCache = [];
    }
}
