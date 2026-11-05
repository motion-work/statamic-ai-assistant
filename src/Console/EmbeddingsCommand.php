<?php

namespace MotionWork\StatamicAiAssistant\Console;

use Illuminate\Console\Command;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndex;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use Statamic\Facades\Site;

/**
 * php artisan statamic-ai-assistant:embeddings build|status|clear [--site=]
 *
 * build embeds every changed entry (re-running it resumes and skips what is
 * up to date), status shows each site's index, clear deletes it (search then
 * falls back to keywords until the next build).
 */
class EmbeddingsCommand extends Command
{
    protected $signature = 'statamic-ai-assistant:embeddings
        {action=status : build, status or clear}
        {--site=* : Only these site handles (default: all sites)}';

    protected $description = 'Build, inspect or clear the semantic search index (embeddings).';

    public function handle(EmbeddingIndexer $indexer, SemanticSearch $semantic): int
    {
        return match ((string) $this->argument('action')) {
            'build' => $this->build($indexer, $semantic),
            'status' => $this->status($semantic),
            'clear' => $this->clear(),
            default => $this->invalidAction(),
        };
    }

    private function build(EmbeddingIndexer $indexer, SemanticSearch $semantic): int
    {
        $reason = $semantic->providerReason();
        if ($reason !== null) {
            $this->error('Semantic search cannot index entries: '.$reason.'. It needs the OpenRouter provider with OPENROUTER_API_KEY and semantic_search.enabled.');

            return self::FAILURE;
        }

        foreach ($this->sites() as $site) {
            $this->info("Site {$site}");
            $bar = $this->output->createProgressBar();
            $result = $indexer->buildSite($site, function (int $done, int $total) use ($bar) {
                $bar->setMaxSteps($total);
                $bar->setProgress($done);
            });
            $bar->finish();
            $this->newLine();
            $this->line("  embedded {$result['indexed']}, unchanged {$result['unchanged']}, removed {$result['removed']}");
        }

        return self::SUCCESS;
    }

    private function status(SemanticSearch $semantic): int
    {
        $rows = [];
        foreach ($this->sites() as $site) {
            $manifest = rescue(fn () => (new EmbeddingIndex($site))->manifest(), null, false);
            $rows[] = [
                $site,
                $manifest === null ? '—' : count($manifest['entries']),
                $manifest['model'] ?? '—',
                $manifest['updated_at'] ?? '—',
                $semantic->reason($site) ?? 'ready',
            ];
        }
        $this->table(['Site', 'Entries', 'Model', 'Updated', 'Search'], $rows);

        return self::SUCCESS;
    }

    private function clear(): int
    {
        foreach ($this->sites() as $site) {
            (new EmbeddingIndex($site))->clear();
            $this->line("Cleared the index of {$site}.");
        }

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use build, status or clear.');

        return self::INVALID;
    }

    /** @return list<string> */
    private function sites(): array
    {
        $only = array_filter((array) $this->option('site'));
        $all = Site::all()->map->handle()->values()->all();

        return $only === [] ? $all : array_values(array_intersect($all, $only));
    }
}
