<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\TranslateTextOptions;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class EntryTranslator
{
    private FieldTextCollector $collector;

    private ReferenceRemapper $references;

    /** The connector's in-place edits; shares this translator's text collector. */
    public readonly EntryEditSegments $editSegments;

    private DeeplService $deepl;

    private EntryReferenceResolver $referenceResolver;

    private LocalizationWriter $writer;

    private string $sourceLang;

    private string $targetLang;

    /** @var array<string, array{entry_id: string, title: string, edit_url: string|null, collection_handle: string, collection_title: string}> */
    private array $linkedEntriesBuffer = [];

    public function __construct(
        DeeplService $deeplService,
        EntryReferenceResolver $entryReferenceResolver,
    ) {
        $this->deepl = $deeplService;
        $this->collector = new FieldTextCollector;
        $this->references = new ReferenceRemapper($entryReferenceResolver);
        $this->editSegments = new EntryEditSegments($this->collector);
        $this->referenceResolver = $entryReferenceResolver;
        $this->writer = new LocalizationWriter;
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
        $lock = Cache::lock(
            'ai-translate:'.$originEntry->id().':'.$targetSite,
            600,
        );

        try {
            $locked = $lock->block(30);
        } catch (LockTimeoutException) {
            Log::warning('[ai-translate] lock wait timed out, translating without it', [
                'entry' => $originEntry->id(),
                'site' => $targetSite,
            ]);
            $locked = false;
        }

        try {
            return $this->translateEntryLocked($originEntry, $targetSite, $existingTarget, $currentDepth, $maxDepth);
        } finally {
            if ($locked) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    // The translation is saved; a failed release (e.g. the database lock
                    // store is unreachable) only lets the lock run out on its own.
                    Log::warning('[ai-translate] lock release failed', ['error' => $e->getMessage()]);
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
            $this->collector->policy->skippedNonLocalizable = [];
            $this->referenceResolver->resetState();
        }

        $this->sourceLang = $originEntry->locale();
        $this->targetLang = $targetSite;

        $blueprint = $originEntry->blueprint();
        $originData = $originEntry->data()->toArray();
        $fields = (new TranslatableFields)->getFieldDefinitions($blueprint);

        // Phase 1: Collect all translatable texts into a flat list
        $this->collector->segments->resetCollector();
        $dataWithPlaceholders = $this->collector->collectFromEntryFields($originData, $fields, $currentDepth, $maxDepth);

        // Phase 2: One single API call for ALL texts in this entry
        $translatedTexts = [];
        if (! empty($this->collector->segments->collectedTexts)) {
            $translatedTexts = $this->deepl->translateBatch(
                $this->collector->segments->prepareTextsForApi(),
                $this->sourceLang,
                $this->targetLang,
                [TranslateTextOptions::TAG_HANDLING => 'html'],
            );

            $translatedTexts = $this->collector->segments->decodeTranslatedTexts($translatedTexts);
        }

        // Phase 3: Replace placeholders with translated texts
        $translatedData = $this->collector->segments->replaceInFields($dataWithPlaceholders, $translatedTexts);

        $translatedSlug = $this->writer->slug($translatedData, $originEntry);

        // Resolve entry references (ID remapping, not translation)
        $translatedData = $this->references->resolveReferences($translatedData, $fields, $targetSite, $currentDepth, $maxDepth);

        $targetEntry = $existingTarget ?? $this->writer->target(
            $originEntry,
            $targetSite,
            $blueprint->handle(),
            $this->referenceResolver->getCachedTargetId($originEntry->id(), $targetSite),
        );

        $this->writer->save($targetEntry, $translatedData, $translatedSlug, $originEntry);
        $this->writer->commit($originEntry, $targetSite, $currentDepth);

        if ($currentDepth > 0) {
            $this->recordLinkedEntry($targetEntry);
        }

        return $targetEntry;
    }

    /**
     * A linked entry translated along the way, reported to the editor after the run.
     */
    private function recordLinkedEntry(mixed $targetEntry): void
    {
        $coll = Collection::findByHandle($targetEntry->collectionHandle());
        $this->linkedEntriesBuffer[$targetEntry->id()] = [
            'entry_id' => $targetEntry->id(),
            'title' => EntryLabel::for($targetEntry),
            'edit_url' => $targetEntry->editUrl(),
            'collection_handle' => $targetEntry->collectionHandle(),
            'collection_title' => $coll ? $coll->title() : $targetEntry->collectionHandle(),
        ];
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

        $fields = (new TranslatableFields)->getFieldDefinitions($blueprint);
        $data = $localizedEntry->data()->toArray();

        $this->referenceResolver->resetState();
        $remapped = $this->references->resolveReferences($data, $fields, (string) $localizedEntry->locale(), 0, 0);

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
     * @return array<int, array{id: string, text: string, html: bool, field?: string}>
     */
    public function externalSegments(mixed $entry): array
    {
        $this->collector->segments->resetCollector();
        $this->sourceLang = (string) $entry->locale();
        $blueprint = $entry->blueprint();
        $fields = $blueprint ? (new TranslatableFields)->getFieldDefinitions($blueprint) : [];
        $this->collector->collectFromEntryFields($entry->data()->toArray(), $fields, 0, 0);

        return $this->editSegments->collectedSegments();
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
        $this->collector->policy->skippedNonLocalizable = [];
        $this->collector->segments->resetCollector();
        $this->sourceLang = (string) $textEntry->locale();
        $this->targetLang = $targetSite;

        $blueprint = $textEntry->blueprint();
        $fields = $blueprint ? (new TranslatableFields)->getFieldDefinitions($blueprint) : [];
        $dataWithPlaceholders = $this->collector->collectFromEntryFields($textEntry->data()->toArray(), $fields, 0, 0);

        $translated = [];
        foreach ($this->collector->segments->collectedTexts as $index => $unused) {
            $key = (string) $index;
            if (! array_key_exists($key, $translationsById) || ! is_string($translationsById[$key])) {
                throw new \InvalidArgumentException('Missing translation for segment '.$key.'.');
            }
            $translated[$index] = $translationsById[$key];
        }

        $translatedData = $this->collector->segments->replaceInFields($dataWithPlaceholders, $translated);
        $translatedData = $this->references->resolveReferences($translatedData, $fields, $targetSite, 0, 0);

        $targetEntry = $existingTarget ?? $this->writer->target($originEntry, $targetSite, $blueprint?->handle());

        $this->writer->save($targetEntry, $translatedData, $this->writer->slug($translatedData, $textEntry), $originEntry);
        $this->writer->commit($originEntry, $targetSite, 0);

        return $targetEntry;
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

    /**
     * Text fields left in the source language because they are not localizable
     * (drained: each call returns the ones since the last).
     *
     * @return array<int, string>
     */
    public function takeSkippedNonLocalizable(): array
    {
        return $this->collector->policy->takeSkippedNonLocalizable();
    }
}
