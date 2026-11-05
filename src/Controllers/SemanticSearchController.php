<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use MotionWork\StatamicAiAssistant\Jobs\BuildEmbeddingIndexJob;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndex;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\EmbedsText;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The "Semantic search" card on the AI usage page (super admins only, see
 * routes/cp.php): index status per site, and "Rebuild index".
 */
class SemanticSearchController extends CpController
{
    public function status(SemanticSearch $semantic, AbstractAiService $ai): JsonResponse
    {
        $reason = $semantic->providerReason();

        return response()->json([
            'available' => $reason === null,
            'reason' => $reason,
            'model' => $ai instanceof EmbedsText ? $ai->embeddingModel() : null,
            'sites' => Site::all()->map(fn ($site) => $this->siteStatus($semantic, $site->handle(), $site->name()))->values()->all(),
        ]);
    }

    public function rebuild(SemanticSearch $semantic): JsonResponse
    {
        if ($semantic->providerReason() !== null) {
            return response()->json(['message' => __('Semantic search needs the OpenRouter provider with an API key.')], 422);
        }

        BuildEmbeddingIndexJob::dispatch();

        return response()->json(['queued' => true]);
    }

    /**
     * @return array{site: string, name: string, indexed: int, entries: int, updated_at: ?string, reason: ?string}
     */
    private function siteStatus(SemanticSearch $semantic, string $handle, string $name): array
    {
        $manifest = rescue(fn () => (new EmbeddingIndex($handle))->manifest(), null, false);
        $entries = Entry::query()->where('site', $handle)->count();

        return [
            'site' => $handle,
            'name' => $name,
            'indexed' => count($manifest['entries'] ?? []),
            'entries' => $entries,
            'updated_at' => $manifest['updated_at'] ?? null,
            // A site without entries has nothing to index: say so, not "not built".
            'reason' => $entries === 0 ? 'no_entries' : $semantic->reason($handle),
        ];
    }
}
