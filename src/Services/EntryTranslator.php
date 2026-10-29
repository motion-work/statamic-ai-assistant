<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Mcp\ContentCommitter;
use MotionWork\StatamicAiAssistant\Services\Concerns\TranslatesFields;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Facades\User;
use DeepL\TranslateTextOptions;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class EntryTranslator
{
    use TranslatesFields;

    private const REFERENCE_TYPES = ['entries', 'link'];

    private DeeplService $deepl;

    private EntryReferenceResolver $referenceResolver;

    private string $sourceLang;

    private string $targetLang;

    /** @var array<string, array{entry_id: string, title: string, edit_url: string|null, collection_handle: string, collection_title: string}> */
    private array $linkedEntriesBuffer = [];

    public function __construct(
        DeeplService $deeplService,
        EntryReferenceResolver $entryReferenceResolver,
    ) {
        $this->deepl = $deeplService;
        $this->referenceResolver = $entryReferenceResolver;
        $this->referenceResolver->setEntryTranslator($this);
    }

    public function translateEntry(
        mixed $originEntry,
        string $targetSite,
        mixed $existingTarget = null,
        int $currentDepth = 0,
        int $maxDepth = 1,
    ): StatamicEntry {
        // Bulk runs execute one job per (entry × locale) on PARALLEL workers,
        // and a job may also create LINKED entries' localizations recursively.
        // Without a lock, two workers can create two different localizations
        // for the same (origin, site) pair — split-brain translations. The lock
        // serializes creation per pair; on timeout we degrade to today's
        // behavior rather than failing the job.
        $lock = \Illuminate\Support\Facades\Cache::lock(
            'ai-translate:'.$originEntry->id().':'.$targetSite,
            600,
        );

        $locked = false;
        try {
            $locked = $lock->block(30);
        } catch (\Throwable) {
            $locked = false;
        }

        try {
            return $this->translateEntryLocked($originEntry, $targetSite, $existingTarget, $currentDepth, $maxDepth);
        } finally {
            if ($locked) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Lock expired during a long translation — nothing to release.
                }
            }
        }
    }

    private function translateEntryLocked(
        mixed $originEntry,
        string $targetSite,
        mixed $existingTarget = null,
        int $currentDepth = 0,
        int $maxDepth = 1,
    ): StatamicEntry {
        if ($currentDepth === 0) {
            $this->linkedEntriesBuffer = [];
            $this->skippedNonLocalizable = [];
        }

        $this->sourceLang = $originEntry->locale();
        $this->targetLang = $targetSite;

        $blueprint = $originEntry->blueprint();
        $originData = $originEntry->data()->toArray();
        $fields = $this->getFieldDefinitions($blueprint);

        // Phase 1: Collect all translatable texts into a flat list
        $this->resetCollector();
        $dataWithPlaceholders = $this->collectFromEntryFields($originData, $fields, $currentDepth, $maxDepth);

        // Phase 2: One single API call for ALL texts in this entry
        $translatedTexts = [];
        if (! empty($this->collectedTexts)) {
            $translatedTexts = $this->deepl->translateBatch(
                $this->prepareTextsForApi(),
                $this->sourceLang,
                $this->targetLang,
                [TranslateTextOptions::TAG_HANDLING => 'html'],
            );

            $translatedTexts = $this->decodeTranslatedTexts($translatedTexts);
        }

        // Phase 3: Replace placeholders with translated texts
        $translatedData = $this->replaceInFields($dataWithPlaceholders, $translatedTexts);

        $translatedTitle = $translatedData['title'] ?? '';
        if (is_string($translatedTitle) && $translatedTitle !== '') {
            $translatedSlug = Str::slug($translatedTitle);
        } else {
            $translatedSlug = Str::slug($originEntry->slug());
        }

        // Resolve entry references (ID remapping, not translation)
        $translatedData = $this->resolveReferences($translatedData, $fields, $targetSite, $currentDepth, $maxDepth);

        $targetEntry = $existingTarget;

        // Re-check for a localization created while we were translating or
        // waiting on the lock (another worker, or a recursive resolution in a
        // parallel job) — update it instead of creating a duplicate.
        if (! $targetEntry) {
            $targetEntry = Entry::query()
                ->where('origin', $originEntry->id())
                ->where('site', $targetSite)
                ->first();
        }

        if (! $targetEntry) {
            $cachedTargetId = $this->referenceResolver->getCachedTargetId($originEntry->id(), $targetSite);
            if ($cachedTargetId) {
                $targetEntry = Entry::find($cachedTargetId);
                if (! $targetEntry) {
                    $targetEntry = Entry::make()
                        ->id($cachedTargetId)
                        ->collection($originEntry->collectionHandle())
                        ->blueprint($blueprint->handle())
                        ->locale($targetSite)
                        ->origin($originEntry->id());
                }
            }
        }
        if (! $targetEntry) {
            $targetEntry = Entry::make()
                ->collection($originEntry->collectionHandle())
                ->blueprint($blueprint->handle())
                ->locale($targetSite)
                ->origin($originEntry->id());
        }

        $targetEntry->slug($translatedSlug);
        $targetEntry->data($translatedData);

        if ($originEntry->published()) {
            $targetEntry->published(true);
        }

        // Use saveQuietly to prevent EntrySaving/EntrySaved events from
        // triggering side-effects in packages like stillat/relationships,
        // which would otherwise sync bidirectional references and corrupt
        // the origin (source-language) entries.
        $targetEntry->saveQuietly();
        $this->queueContentCommit($originEntry, $targetSite, $currentDepth);

        if ($currentDepth > 0) {
            $coll = Collection::findByHandle($targetEntry->collectionHandle());
            $this->linkedEntriesBuffer[$targetEntry->id()] = [
                'entry_id' => $targetEntry->id(),
                'title' => EntryLabel::for($targetEntry),
                'edit_url' => $targetEntry->editUrl(),
                'collection_handle' => $targetEntry->collectionHandle(),
                'collection_title' => $coll ? $coll->title() : $targetEntry->collectionHandle(),
            ];
        }

        return $targetEntry;
    }

    /**
     * Re-run ONLY the reference pass over an already-translated localization,
     * in remap-only mode (maxDepth 0: swap ids whose target localization now
     * exists, never create anything). Used by the post-batch reconcile step to
     * heal references that pointed at source-site entries because the linked
     * entry had not been translated yet when this one was. Returns true when
     * the entry changed and was saved.
     */
    public function remapEntryReferences(mixed $localizedEntry): bool
    {
        $blueprint = $localizedEntry->blueprint();

        if (! $blueprint) {
            return false;
        }

        $fields = $this->getFieldDefinitions($blueprint);
        $data = $localizedEntry->data()->toArray();

        $remapped = $this->resolveReferences($data, $fields, (string) $localizedEntry->locale(), 0, 0);

        if ($remapped === $data) {
            return false;
        }

        $localizedEntry->data($remapped);
        $localizedEntry->saveQuietly();

        return true;
    }

    /**
     * Texts Claude (or another external translator) should translate.
     * Nothing is sent to DeepL or the configured AI provider.
     *
     * @return array<int, array{id: string, text: string, html: bool}>
     */
    public function externalSegments(mixed $entry): array
    {
        $this->resetCollector();
        $this->sourceLang = (string) $entry->locale();
        $blueprint = $entry->blueprint();
        $fields = $blueprint ? $this->getFieldDefinitions($blueprint) : [];
        $this->collectFromEntryFields($entry->data()->toArray(), $fields, 0, 0);

        return $this->collectedSegments();
    }

    /**
     * Every text the entry holds itself, for editing it in place. Unlike
     * {@see externalSegments()}, fields marked localizable: false count too,
     * because the edit writes back into the same entry.
     *
     * @return array<int, array{id: string, text: string, html: bool}>
     */
    public function editableSegments(mixed $entry): array
    {
        $this->resetCollector();
        $blueprint = $entry->blueprint();
        $fields = $blueprint ? $this->getFieldDefinitions($blueprint) : [];
        $this->collectFromEntryFields($entry->data()->toArray(), $fields, 0, 0, true);

        return $this->collectedSegments();
    }

    /**
     * Put edited text back into the same entry, keyed by the ids from
     * {@see editableSegments()}. Segments left out keep their current value
     * exactly. The entry is not saved.
     *
     * @param  array<string, string>  $editsById
     */
    public function applySegmentEdits(mixed $entry, array $editsById): StatamicEntry
    {
        $this->resetCollector();
        $blueprint = $entry->blueprint();
        $fields = $blueprint ? $this->getFieldDefinitions($blueprint) : [];
        $data = $entry->data()->toArray();
        $withPlaceholders = $this->collectFromEntryFields($data, $fields, 0, 0, true);

        $edits = [];
        foreach ($editsById as $id => $text) {
            if (isset($this->collectedTexts[(int) $id]) && is_string($text)) {
                $edits[(int) $id] = $text;
            }
        }

        $entry->data(array_merge($data, $this->replaceInFields($withPlaceholders, $edits)));

        return $entry;
    }

    /**
     * @return array<int, array{id: string, text: string, html: bool}>
     */
    private function collectedSegments(): array
    {
        $segments = [];
        foreach ($this->collectedTexts as $index => $text) {
            $segments[] = [
                'id' => (string) $index,
                'text' => $text,
                'html' => isset($this->htmlTexts[$index]),
            ];
        }

        return $segments;
    }

    /**
     * Save a localization whose text was translated outside this addon.
     * `$translationsById` is keyed by the ids from {@see externalSegments()}.
     * DeepL and the configured AI provider are not called.
     *
     * `$textEntry` supplies the segments. `$originEntry` is the root the
     * localization stays linked to; it defaults to `$textEntry`.
     *
     * @param  array<string, string>  $translationsById
     */
    public function saveExternalTranslation(mixed $textEntry, string $targetSite, array $translationsById, mixed $existingTarget = null, mixed $originEntry = null): StatamicEntry
    {
        $originEntry = $originEntry ?? $textEntry;
        $this->linkedEntriesBuffer = [];
        $this->skippedNonLocalizable = [];
        $this->resetCollector();
        $this->sourceLang = (string) $textEntry->locale();
        $this->targetLang = $targetSite;

        $blueprint = $textEntry->blueprint();
        $fields = $blueprint ? $this->getFieldDefinitions($blueprint) : [];
        $dataWithPlaceholders = $this->collectFromEntryFields($textEntry->data()->toArray(), $fields, 0, 0);

        $translated = [];
        foreach ($this->collectedTexts as $index => $unused) {
            $key = (string) $index;
            if (! array_key_exists($key, $translationsById) || ! is_string($translationsById[$key])) {
                throw new \InvalidArgumentException('Missing translation for segment '.$key.'.');
            }
            $translated[$index] = $translationsById[$key];
        }

        $translatedData = $this->replaceInFields($dataWithPlaceholders, $translated);
        $translatedData = $this->resolveReferences($translatedData, $fields, $targetSite, 0, 0);

        $translatedTitle = $translatedData['title'] ?? '';
        $translatedSlug = is_string($translatedTitle) && $translatedTitle !== ''
            ? Str::slug($translatedTitle)
            : Str::slug((string) $textEntry->slug());

        $targetEntry = $existingTarget;
        if (! $targetEntry) {
            $targetEntry = Entry::query()
                ->where('origin', $originEntry->id())
                ->where('site', $targetSite)
                ->first();
        }
        if (! $targetEntry) {
            $targetEntry = Entry::make()
                ->collection($originEntry->collectionHandle())
                ->blueprint($blueprint?->handle())
                ->locale($targetSite)
                ->origin($originEntry->id());
        }

        $targetEntry->slug($translatedSlug);
        $targetEntry->data($translatedData);

        if ($originEntry->published()) {
            $targetEntry->published(true);
        }

        $targetEntry->saveQuietly();
        $this->queueContentCommit($originEntry, $targetSite, 0);

        return $targetEntry;
    }

    /**
     * Quiet saves skip Statamic's git listener. One commit at the top of a
     * translation covers the entry and any linked localizations already written.
     */
    private function queueContentCommit(mixed $originEntry, string $targetSite, int $currentDepth): void
    {
        if ($currentDepth !== 0) {
            return;
        }

        app(ContentCommitter::class)->commit(
            'Translate "'.EntryLabel::for($originEntry).'" into '.$targetSite,
            User::current(),
        );
    }

    /**
     * @return array<int, array{entry_id: string, title: string, edit_url: string|null, collection_handle: string, collection_title: string}>
     */
    public function takeLinkedEntriesCreated(): array
    {
        $out = array_values($this->linkedEntriesBuffer);
        $this->linkedEntriesBuffer = [];

        return $out;
    }

    public function resetLinkedEntriesBuffer(): void
    {
        $this->linkedEntriesBuffer = [];
    }

    /**
     * Origin ids whose references stayed pointing at source-site entries this
     * run (see EntryReferenceResolver::takeUnresolved()).
     *
     * @return array<int, string>
     */
    public function takeUnresolvedReferences(): array
    {
        return $this->referenceResolver->takeUnresolved();
    }

    // ── Entry-specific collect (handles REFERENCE_TYPES pass-through) ─

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    private function collectFromEntryFields(array $data, array $fields, int $currentDepth, int $maxDepth, bool $everyField = false): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            if ($value === null || $value === '') {
                $result[$handle] = $value;

                continue;
            }

            $fieldDef = $fields[$handle] ?? null;

            // Skip fields not defined in the blueprint (metadata like
            // duplicated_from, updated_by, updated_at) — these should be
            // inherited from the origin, not copied into the localization.
            if ($fieldDef === null) {
                continue;
            }

            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (! $everyField && ! $isLocalizable && ! $this->shouldForceTranslateField($handle, $fieldDef)) {
                continue;
            }

            $result[$handle] = $this->collectFromField($value, $fieldType, $fieldDef);
        }

        return $result;
    }

    // ── Phase 3b: Resolve entry references ───────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    private function resolveReferences(array $data, array $fields, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($data as $handle => &$value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $fieldDef = $fields[$handle] ?? null;
            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (in_array($fieldType, self::REFERENCE_TYPES)) {
                // Only resolve references for localizable fields — non-localizable
                // references are inherited from the origin and must not be remapped.
                if ($isLocalizable) {
                    $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
                }

                continue;
            }

            if (in_array($fieldType, self::BARD_TYPES) && is_array($value)) {
                $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                continue;
            }

            if (in_array($fieldType, self::RECURSIVE_TYPES) && is_array($value)) {
                $value = $this->resolveReplicatorReferences($value, $fieldDef, $targetSite, $currentDepth, $maxDepth);

                continue;
            }
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private function resolveBardEntryLinks(array $nodes, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['marks']) && is_array($node['marks'])) {
                foreach ($node['marks'] as &$mark) {
                    if (($mark['type'] ?? null) === 'link' && isset($mark['attrs']['href'])) {
                        $mark['attrs']['href'] = (string) $this->referenceResolver->resolve(
                            $mark['attrs']['href'],
                            $targetSite,
                            $currentDepth,
                            $maxDepth,
                        );
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = $this->resolveBardEntryLinks($node['content'], $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array{sets?: array, fields?: array}|null  $fieldDef
     * @return array<mixed>
     */
    private function resolveReplicatorReferences(array $sets, ?array $fieldDef, string $targetSite, int $currentDepth, int $maxDepth): array
    {
        $setsDefs = $fieldDef['sets'] ?? null;
        $gridFieldsDefs = $fieldDef['fields'] ?? null;

        foreach ($sets as &$set) {
            if (! is_array($set)) {
                continue;
            }

            $setType = $set['type'] ?? null;
            $subFields = null;

            if ($setsDefs !== null && $setType !== null) {
                $subFields = $setsDefs[$setType] ?? null;
            } elseif ($gridFieldsDefs !== null) {
                $subFields = $gridFieldsDefs;
            }

            foreach ($set as $key => &$value) {
                if (in_array($key, ['id', 'type', 'enabled']) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                if ($subFields !== null) {
                    $subFieldType = $subFields[$key]['type'] ?? null;
                    $subFieldDef = $subFields[$key] ?? null;

                    if (in_array($subFieldType, self::REFERENCE_TYPES)) {
                        $value = $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, self::BARD_TYPES) && is_array($value)) {
                        $value = $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }

                    if (in_array($subFieldType, self::RECURSIVE_TYPES) && is_array($value)) {
                        $value = $this->resolveReplicatorReferences($value, $subFieldDef, $targetSite, $currentDepth, $maxDepth);

                        continue;
                    }
                } else {
                    $value = $this->resolveReplicatorReferenceFallback($value, $targetSite, $currentDepth, $maxDepth);
                }
            }
        }

        return $sets;
    }

    private function resolveReplicatorReferenceFallback(mixed $value, string $targetSite, int $currentDepth, int $maxDepth): mixed
    {
        if (is_string($value) && $this->looksLikeEntryReference($value)) {
            return (string) $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
        }

        if (is_array($value)) {
            if ($this->isBardContent($value)) {
                return $this->resolveBardEntryLinks($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ($this->isUuidArray($value)) {
                return $this->referenceResolver->resolve($value, $targetSite, $currentDepth, $maxDepth);
            }

            if ($this->isNestedSets($value)) {
                return $this->resolveReplicatorReferences($value, null, $targetSite, $currentDepth, $maxDepth);
            }
        }

        return $value;
    }
}
