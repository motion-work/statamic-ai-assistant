<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the queue can run AI agent batches: not the sync driver, and the
 * job_batches (and failed-jobs) tables migrated.
 */
final class QueueReadiness
{
    public function setupError(): ?string
    {
        if (config('queue.default') === 'sync') {
            return (string) __(
                'AI agent batch generation requires an asynchronous queue. Set QUEUE_CONNECTION=redis in your .env, then start php artisan horizon or php artisan queue:work. The sync driver cannot be used.',
            );
        }

        if ($missing = $this->missingQueueDatabaseTables()) {
            return (string) __(
                'The addon queue relies on database tables that are not set up yet (:missing). Run `php artisan migrate --force` to create them, then restart php artisan horizon or php artisan queue:work.',
                ['missing' => implode(', ', $missing)],
            );
        }

        return null;
    }

    /**
     * Queue-related database tables that are missing or unreachable — typically
     * because `php artisan migrate --force` has not been run locally. Empty when
     * the database is ready. Mirrors the Horizon/sync setup guard above.
     */
    private function missingQueueDatabaseTables(): array
    {
        $checks = [];

        if (config('queue.failed.driver') === 'database-uuids') {
            $checks[] = [
                config('queue.failed.database', config('database.default')),
                config('queue.failed.table', 'failed_jobs'),
            ];
        }

        // Laravel Bus batching backs the translation/navigation batch runs.
        $checks[] = [
            config('queue.batching.database', config('database.default')),
            config('queue.batching.table', 'job_batches'),
        ];

        $missing = [];

        foreach ($checks as [$connection, $table]) {
            if (! $this->queueTableExists($connection, $table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    private function queueTableExists(string $connection, string $table): bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (\Throwable $e) {
            Log::warning('[entry-gen] queue table check failed', ['connection' => $connection, 'table' => $table, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
