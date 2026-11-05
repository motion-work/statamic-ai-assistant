<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use Statamic\Facades\Site;

/**
 * Builds (or brings up to date) the embedding index of every site, queued by
 * "Rebuild index" on the AI usage page. Only changed entries are embedded.
 */
class BuildEmbeddingIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onQueue(EmbeddingIndexer::queue());
    }

    public function handle(EmbeddingIndexer $indexer): void
    {
        foreach (Site::all() as $site) {
            $result = $indexer->buildSite($site->handle());
            Log::info('Semantic search index built', ['site' => $site->handle(), ...$result]);
        }
    }
}
