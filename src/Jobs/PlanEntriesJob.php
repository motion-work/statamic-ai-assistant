<?php

namespace BoldWeb\StatamicAiAssistant\Jobs;

use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationPlanner;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Support\PlanEntryDecorator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Run the BOLD agent planner asynchronously so the HTTP request returns immediately.
 *
 * - When auto_resolve is true the planner is invoked in agentic mode: it can call
 *   `create_entry_job` to incrementally append plan rows + dispatch generation
 *   jobs in parallel as articles are discovered.
 * - When auto_resolve is false the controller already knows collection+blueprint,
 *   so this job simply appends one decorated row and dispatches one generator
 *   job — no LLM round trip is needed.
 */
class PlanEntriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public string $sessionId)
    {
        $batch = config('statamic-ai-assistant.entry_generator_batch', []);
        $this->queue = (string) ($batch['queue'] ?? 'default');
        // Planner can run for many minutes (URL fetches + LLM rounds); keep generous.
        $this->timeout = max(120, (int) ($batch['job_timeout'] ?? 300));
        $this->tries = (int) ($batch['job_tries'] ?? 1);
    }

    public function handle(
        EntryGenerationBatchService $batch,
        EntryGenerationPlanner $planner,
        EntryGeneratorService $generator,
        PlanEntryDecorator $decorator,
    ): void {
        $session = $batch->getSession($this->sessionId);
        if (! is_array($session)) {
            Log::notice('PlanEntriesJob: session missing', ['session_id' => $this->sessionId]);

            return;
        }

        if ($batch->isCancelled($this->sessionId)) {
            $batch->markPlanningFailed($this->sessionId, (string) __('Cancelled.'));

            return;
        }

        $autoResolve = (bool) ($session['auto_resolve'] ?? true);

        try {
            if (! $autoResolve) {
                $this->planSingleEntry($session, $batch, $generator, $decorator);
                $batch->markPlanningComplete($this->sessionId);

                return;
            }

            $planner->planAgentic($this->sessionId);
        } catch (\Throwable $e) {
            Log::warning('PlanEntriesJob failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);

            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : (string) __('BOLD agent planning failed. Please try again.');

            $batch->markPlanningFailed($this->sessionId, $message);
        }
    }

    /**
     * Non-agentic flow: collection + blueprint already chosen by the CP form.
     *
     * @param  array<string, mixed>  $session
     */
    private function planSingleEntry(
        array $session,
        EntryGenerationBatchService $batch,
        EntryGeneratorService $generator,
        PlanEntryDecorator $decorator,
    ): void {
        $prompt = (string) ($session['prompt'] ?? '');
        $collection = (string) ($session['collection_handle'] ?? '');
        $blueprint = (string) ($session['blueprint_handle'] ?? '');

        if ($collection === '') {
            // Defensive: should never happen because the controller validates non-auto requests.
            $resolved = $generator->resolveTargetFromPrompt($prompt, $session['attachment_content'] ?? null);
            $collection = $resolved['collection'];
            $blueprint = $resolved['blueprint'];
        }

        $decorated = $decorator->decorateOne([
            'collection' => $collection,
            'blueprint' => $blueprint,
            'prompt' => $prompt,
            'label' => Str::limit($prompt, 60),
        ]);

        $cap = max(1, min(500, (int) config('statamic-ai-assistant.bold_agent_max_plan_entries', 100)));
        if ($batch->addPlannedEntry($this->sessionId, $decorated, $cap)) {
            GeneratePlannedEntryJob::dispatch($this->sessionId, (string) $decorated['id']);
        }
    }
}
