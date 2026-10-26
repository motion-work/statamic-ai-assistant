<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use Statamic\Facades\Entry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate one planned entry in a AI agent batch (chained so shared preferred
 * asset paths deplete in order).
 */
class GeneratePlannedEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public string $sessionId,
        public string $entryId,
    ) {
        $batch = config('statamic-ai-assistant.entry_generator_batch', []);
        $this->queue = (string) ($batch['queue'] ?? 'default');
        $this->timeout = (int) ($batch['job_timeout'] ?? 300);
        $this->tries = (int) ($batch['job_tries'] ?? 2);
    }

    public function handle(
        EntryGenerationBatchService $batch,
        EntryGeneratorService $generator,
        ?TranslationService $translations = null,
    ): void {
        if ($batch->isCancelled($this->sessionId)) {
            $batch->markEntryFailed($this->sessionId, $this->entryId, (string) __('Cancelled.'));

            return;
        }

        $session = $batch->getSession($this->sessionId);
        if (! is_array($session) || ! isset($session['entries'][$this->entryId])) {
            Log::notice('GeneratePlannedEntryJob: session or entry missing', [
                'session_id' => $this->sessionId,
                'entry_id' => $this->entryId,
            ]);

            return;
        }

        $row = $session['entries'][$this->entryId];
        $entryStatus = (string) ($row['status'] ?? 'pending');
        if ($entryStatus === 'ready' || $entryStatus === 'failed' || $entryStatus === 'saved') {
            return;
        }

        $locale = (string) ($session['locale'] ?? '');
        $attachment = isset($session['attachment_content']) && is_string($session['attachment_content'])
            ? $session['attachment_content']
            : null;

        $batch->markEntryGenerating($this->sessionId, $this->entryId);

        if ($batch->isCancelled($this->sessionId)) {
            $batch->markEntryFailed($this->sessionId, $this->entryId, (string) __('Cancelled.'));

            return;
        }

        if ((string) ($row['operation'] ?? '') === 'localize') {
            $this->localizeEntry($batch, $translations ?? app(TranslationService::class), $row);

            return;
        }

        $prefetched = $batch->buildPrefetchedUrlAugmentation($this->sessionId);
        $statamicEntryId = isset($row['entry_id']) && is_string($row['entry_id']) && $row['entry_id'] !== ''
            ? $row['entry_id']
            : null;

        try {
            $streamSink = function (string $delta) use ($batch): void {
                if ($delta === '') {
                    return;
                }
                $batch->recordStreamDelta($this->sessionId, $this->entryId, $delta);
            };

            if ($statamicEntryId !== null) {
                // Update path: kept fully separate from the create path so it can
                // be removed/swapped without touching generateContent.
                $result = $generator->generateUpdateForEntry(
                    $statamicEntryId,
                    (string) ($row['prompt'] ?? ''),
                    $locale,
                    $attachment,
                    $streamSink,
                    $prefetched,
                    null,
                );
            } else {
                $result = $generator->generateContent(
                    (string) ($row['collection'] ?? ''),
                    (string) ($row['blueprint'] ?? ''),
                    (string) ($row['prompt'] ?? ''),
                    $locale,
                    $attachment,
                    $streamSink,
                    null,
                    $prefetched,
                    null,
                );
            }

            $batch->setPreferredPaths(
                $this->sessionId,
                $prefetched['preferred']->remainingEntries(),
            );

            $batch->markEntrySuccess($this->sessionId, $this->entryId, [
                'data' => $result['data'],
                'displayData' => $result['displayData'],
                'warnings' => $result['warnings'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('GeneratePlannedEntryJob failed', [
                'session_id' => $this->sessionId,
                'entry_id' => $this->entryId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'is_update' => $statamicEntryId !== null,
            ]);

            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : (string) __('Entry generation failed. Please try again.');

            $batch->markEntryFailed($this->sessionId, $this->entryId, $message);
        }
    }

    /**
     * Localize the source entry onto the target site and translate it there.
     * The localization keeps the source as its origin. It is saved immediately,
     * so the CP card never offers "save as draft" on the current site.
     *
     * @param  array<string, mixed>  $row
     */
    private function localizeEntry(EntryGenerationBatchService $batch, TranslationService $translations, array $row): void
    {
        $sourceId = isset($row['entry_id']) && is_string($row['entry_id']) ? $row['entry_id'] : '';
        $source = $sourceId !== '' ? Entry::find($sourceId) : null;
        $targetLocale = isset($row['target_locale']) && is_string($row['target_locale']) ? $row['target_locale'] : '';

        if (! $source || $targetLocale === '') {
            $batch->markEntryFailed($this->sessionId, $this->entryId, (string) __('Source entry was not found.'));

            return;
        }

        try {
            $result = $translations->translateEntry(
                $source,
                (string) $source->site()->locale(),
                $targetLocale,
                true,
            );
        } catch (\Throwable $e) {
            Log::warning('GeneratePlannedEntryJob localization failed', [
                'session_id' => $this->sessionId,
                'entry_id' => $this->entryId,
                'source_entry_id' => $sourceId,
                'target_locale' => $targetLocale,
                'error' => $e->getMessage(),
            ]);

            $batch->markEntryFailed(
                $this->sessionId,
                $this->entryId,
                $e instanceof \RuntimeException ? $e->getMessage() : (string) __('Translation failed.'),
            );

            return;
        }

        if (($result['success'] ?? false) !== true) {
            $batch->markEntryFailed(
                $this->sessionId,
                $this->entryId,
                (string) ($result['error'] ?? __('Translation failed.')),
            );

            return;
        }

        $warnings = [];
        $untranslated = is_array($result['untranslated_fields'] ?? null) ? $result['untranslated_fields'] : [];
        if ($untranslated !== []) {
            $warnings[] = (string) __('Left in the source language: :fields', [
                'fields' => implode(', ', $untranslated),
            ]);
        }
        $unresolved = is_array($result['unresolved_references'] ?? null) ? $result['unresolved_references'] : [];
        if ($unresolved !== []) {
            $warnings[] = (string) __('Some links still point at the source-language entry.');
        }

        $batch->markEntrySaved($this->sessionId, $this->entryId, [
            'entry_id' => (string) ($result['entry_id'] ?? ''),
            'edit_url' => isset($result['edit_url']) && is_string($result['edit_url']) ? $result['edit_url'] : null,
            'title' => (string) ($result['target_title'] ?? $result['title'] ?? ''),
            'warnings' => $warnings,
        ]);
    }
}
