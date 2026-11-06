<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Tools\Advanced\AdvancedToolset;
use MotionWork\StatamicAiAssistant\Tools\AnalyzeImageTool;
use MotionWork\StatamicAiAssistant\Tools\ChatTool;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use MotionWork\StatamicAiAssistant\Tools\ListAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\ListTaxonomiesTool;
use MotionWork\StatamicAiAssistant\Tools\MoveEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ReadDocumentTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ReadGlobalsTool;
use MotionWork\StatamicAiAssistant\Tools\ReadNavTreeTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UpdateAssetTool;
use MotionWork\StatamicAiAssistant\Tools\UrlFetchTool;
use MotionWork\StatamicAiAssistant\Tools\UseAssetsTool;
use MotionWork\StatamicAiAssistant\Usage\UsageAttribution;
use Statamic\Facades\Entry;

/**
 * The read and structural tools of an agentic run, with the observer that
 * records each successful write as work for the summary and the CP feed.
 */
final class PlannerToolbox
{
    public function __construct(
        private AbstractAiService $aiService,
        private EntrySearch $search,
        private PromptUrlFetcher $promptUrlFetcher,
        private ?EntryGenerationBatchService $batch,
    ) {}

    public function agenticRunner(array $messages, AgenticPlanRun $run): ChatToolRunner
    {
        // Shared runner for the stateless read tools (URL fetch + entry-structure
        // read). The stateful job tools (create/update/find) stay outside the
        // runner and are handled via the $fallback in consume() below, since they
        // mutate session/cap state that a generic runner shouldn't own.
        // Existing assets the model selects via use_assets land here and are
        // drained into the session after every tool round, so already-dispatched
        // and future entry jobs alike pick them up as preferred imagery.
        $selectedAssets = new PreferredAssetPaths;

        $toolContext = new ToolContext(
            warningSink: function (string $w) use ($run) {
                $run->toolWarnings[] = $w;
            },
            heartbeat: $run->heartbeat,
            imageSink: $selectedAssets,
            activitySink: fn (string $line) => $this->batch?->appendPlannerActivity($run->sessionId, $line),
        );

        return new ChatToolRunner(
            [...$this->agenticReadTools($messages, $run->advancedTools), ...$this->treeTools($run)],
            $toolContext,
            fn (string $name, array $result) => $this->recordAgenticToolResult($run, $selectedAssets, $name, $result),
        );
    }

    /**
     * The read tools of an agentic run, plus the structural tools when the
     * requesting user holds the advanced-tools grant.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, ChatTool>
     */
    private function agenticReadTools(array $messages, bool $advancedTools): array
    {
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        $toolset = [
            new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch),
            new ReadEntryStructureTool(
                new EntryStructureSerializer,
                fn (?string $collection, string $query, int $limit) => $this->search->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadEntryTool(
                fn (?string $collection, string $query, int $limit) => $this->search->findEntriesShortlist($collection, $query, $limit),
            ),
            new ReadGlobalsTool,
            new ReadNavTreeTool,
            new ListTaxonomiesTool,
            new ListAssetsTool,
            new UseAssetsTool,
            new UpdateAssetTool,
            new ReadDocumentTool,
        ];

        if ($this->aiService->supportsVision()) {
            $toolset[] = new AnalyzeImageTool($this->aiService);
        }

        // Structural tools are appended only for sessions whose requesting user
        // holds the 'advanced_tools' grant — otherwise they don't exist as far
        // as the model is concerned.
        if ($advancedTools) {
            $toolset = array_merge($toolset, AdvancedToolset::tools());
        }

        return $toolset;
    }

    /**
     * move_entry, offered only when some collection keeps its entries in a
     * tree. It acts for the CP user who started the session.
     *
     * @return array<int, ChatTool>
     */
    private function treeTools(AgenticPlanRun $run): array
    {
        if (! MoveEntryTool::isUseful()) {
            return [];
        }

        return [new MoveEntryTool(
            new EntryMover,
            fn () => UsageAttribution::sessionOwner($run->sessionId) ?? UsageAttribution::currentUserId(),
        )];
    }

    /**
     * Observer for every read/structural tool result: drains use_assets picks
     * into the session and records successful writes as work for the summary
     * and the CP operations feed.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordAgenticToolResult(AgenticPlanRun $run, PreferredAssetPaths $selectedAssets, string $name, array $result): void
    {
        // Drain use_assets picks into the session IMMEDIATELY — a
        // create_entry_job later in the same tool round must already see
        // them as preferred imagery when its queue job starts.
        if ($name === 'use_assets' && ! $selectedAssets->isEmpty()) {
            $this->batch?->appendPreferredAssetPaths($run->sessionId, $selectedAssets->remainingEntries());
        }

        $write = $this->describeAgenticWrite($name, $result);
        if ($write === null) {
            return;
        }

        $run->structuralWrites++;
        [$operation, $description] = $write;

        if ($description !== null) {
            $run->structuralChanges[] = $description;

            // Persistent per-turn operations checklist for the CP progress UI —
            // entries get cards, structural work gets these rows.
            $this->batch?->appendOperation($run->sessionId, $operation, $description);
        }
    }

    /**
     * A successful write the run counts as real work: a run that only changed
     * structure or asset metadata must complete successfully even though no
     * entry jobs were dispatched. Returns [operation kind for the CP feed,
     * summary line (null: none)], or null when the call was not a write.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: ?string}|null
     */
    private function describeAgenticWrite(string $name, array $result): ?array
    {
        if (($result['ok'] ?? false) !== true) {
            return null;
        }

        return match (true) {
            $name === 'update_asset' => $this->describeAssetUpdate($result),
            $name === 'create_terms' => $this->describeNewTerms($result),
            $name === 'move_entry' => ['entry', is_string($result['label'] ?? null) ? $result['label'] : null],
            in_array($name, AdvancedToolset::writeToolNames(), true) => [explode('_', $name)[1] ?? 'structure', $this->describeStructuralChange($name, $result)],
            default => null,
        };
    }

    /**
     * Asset metadata writes are real work too: a run that only filled alt
     * texts must complete successfully, appear in the live operations feed,
     * and be named in the summary. No-op calls (updated=false: every value
     * already matched) are NOT work and must never surface as a change.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: string}|null
     */
    private function describeAssetUpdate(array $result): ?array
    {
        if (($result['updated'] ?? false) !== true) {
            return null;
        }

        return ['asset', (string) __('asset ":file" updated (:fields)', [
            'file' => basename((string) ($result['ref'] ?? '')),
            'fields' => implode(', ', array_filter((array) ($result['applied'] ?? []), 'is_string')),
        ])];
    }

    /**
     * create_terms adds N terms in one call, so it carries a list rather than
     * a single `handle`. A call where every term already existed (created ==
     * []) is a no-op and must not surface as work.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: string}|null
     */
    private function describeNewTerms(array $result): ?array
    {
        $newTerms = array_values(array_filter((array) ($result['created'] ?? []), 'is_string'));

        if ($newTerms === []) {
            return null;
        }

        return ['taxonomy', (string) __('terms :list added to taxonomy ":taxonomy"', [
            'list' => implode(', ', $newTerms),
            'taxonomy' => (string) ($result['taxonomy'] ?? ''),
        ])];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function describeStructuralChange(string $name, array $result): ?string
    {
        $handle = isset($result['handle']) && is_string($result['handle']) ? $result['handle'] : '';

        if ($handle === '') {
            return null;
        }

        return (string) match ($name) {
            'create_collection' => __('new collection ":handle"', ['handle' => $handle]),
            'configure_collection' => __('collection ":handle" configured', ['handle' => $handle]),
            'create_taxonomy' => __('new taxonomy ":handle"', ['handle' => $handle]),
            'create_blueprint' => __('new blueprint ":handle"', ['handle' => $handle]),
            'update_blueprint' => __('blueprint ":handle" updated', ['handle' => $handle]),
            'create_fieldset' => __('new fieldset ":handle"', ['handle' => $handle]),
            'add_component_set' => __('component ":handle" registered in ":container"', ['handle' => $handle, 'container' => (string) ($result['container'] ?? '')]),
            default => __('structural change (:name)', ['name' => $name]),
        };
    }
}
