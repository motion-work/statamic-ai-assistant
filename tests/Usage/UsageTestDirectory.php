<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Support\Facades\File;

/**
 * Points the usage log at a fresh temp directory for each test.
 */
trait UsageTestDirectory
{
    protected string $usageDir;

    protected function useTempUsageDirectory(): void
    {
        $this->usageDir = sys_get_temp_dir().'/ai-usage-'.uniqid();
        config([
            'statamic-ai-assistant.usage_path' => $this->usageDir,
            'statamic-ai-assistant.usage_tracking' => true,
            'statamic-ai-assistant.usage_retention_months' => 12,
        ]);
    }

    protected function removeTempUsageDirectory(): void
    {
        File::deleteDirectory($this->usageDir);
    }

    /** @return list<array<string, mixed>> */
    protected function loggedRecords(?string $month = null): array
    {
        $path = $this->usageDir.'/'.($month ?? now()->format('Y-m')).'.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_map(fn (string $line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
