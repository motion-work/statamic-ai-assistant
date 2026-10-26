<?php

namespace BoldWeb\StatamicAiAssistant\Jobs;

use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate one planned entry in a BOLD agent batch (chained so shared preferred
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

    public function handle(EntryGenerationBatchService $batch, EntryGeneratorService $generator): void
    {
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
        if ($entryStatus === 'ready' || $entryStatus === 'failed') {
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
}
