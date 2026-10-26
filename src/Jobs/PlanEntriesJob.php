<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Run the AI agent planner asynchronously so the HTTP request returns immediately.
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

        $cancelMessage = (string) __('Cancelled.');

        if ($batch->isCancelled($this->sessionId)) {
            $batch->markPlanningFailed($this->sessionId, $cancelMessage);

            return;
        }

        $autoResolve = (bool) ($session['auto_resolve'] ?? true);

        // Assets the user explicitly referenced ("use this image everywhere
        // @asset:…") are queued as preferred imagery DETERMINISTICALLY, before
        // any planning — never depending on the model calling use_assets. Runs
        // for both the agentic and the preselected-collection path.
        $this->seedPreferredAssetsFromMentions($session, $batch);

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
                : (string) __('AI agent planning failed. Please try again.');

            $batch->markPlanningFailed($this->sessionId, $message);

            // Record the failure as an assistant turn so the chat history is
            // coherent for follow-ups — except a plain cancellation, which is
            // user-driven and not worth surfacing as an agent message.
            if ($message !== $cancelMessage) {
                $batch->appendAssistantTurn($this->sessionId, $message, [], 'error');
            }
        }
    }

    /**
     * Auto-queue "@asset:"/"@folder:" mentions from the newest user message as
     * preferred imagery (deduplicated on the session), so every asset field the
     * generator fills draws from what the user referenced instead of random
     * container assets.
     *
     * @param  array<string, mixed>  $session
     */
    private function seedPreferredAssetsFromMentions(array $session, EntryGenerationBatchService $batch): void
    {
        $newest = (string) ($session['prompt'] ?? '');
        foreach (is_array($session['transcript'] ?? null) ? $session['transcript'] : [] as $turn) {
            if (($turn['role'] ?? '') === 'user' && is_string($turn['text'] ?? null)) {
                $newest = $turn['text'];
            }
        }

        $preferred = (new \MotionWork\StatamicAiAssistant\Services\PromptAssetMentionResolver)
            ->resolve([$newest])['preferred'];

        if ($preferred !== []) {
            $batch->appendPreferredAssetPaths($this->sessionId, $preferred);
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

        $cap = max(1, min(500, (int) config('statamic-ai-assistant.agent_max_plan_entries', 100)));
        if ($batch->addPlannedEntry($this->sessionId, $decorated, $cap)) {
            GeneratePlannedEntryJob::dispatch($this->sessionId, (string) $decorated['id']);
        }
    }
}
