<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use Illuminate\Support\Facades\File;

/**
 * Flat-file log of AI calls: one JSON line per call in `<YYYY-MM>.jsonl`
 * under `usage_path` (storage/app/statamic-ai-assistant/usage by default).
 *
 * No database, like the rest of the addon. Appends take an exclusive lock, so
 * queue workers and CP requests can record at the same time.
 */
final class UsageLog
{
    private const MONTH_PATTERN = '/^\d{4}-\d{2}$/';

    public function directory(): string
    {
        $configured = trim((string) config('statamic-ai-assistant.usage_path', ''));

        return $configured !== '' ? $configured : storage_path('app/statamic-ai-assistant/usage');
    }

    public static function isMonth(string $month): bool
    {
        return preg_match(self::MONTH_PATTERN, $month) === 1;
    }

    public function path(string $month): string
    {
        return $this->directory().'/'.$month.'.jsonl';
    }

    /** @param  array<string, mixed>  $record  One call, as built by UsageRecord. */
    public function append(array $record): void
    {
        $month = substr((string) ($record['ts'] ?? now()->toIso8601String()), 0, 7);
        File::ensureDirectoryExists($this->directory());

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->path($month), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Months that have a log, newest first.
     *
     * @return list<string>
     */
    public function months(): array
    {
        $months = array_map(
            fn (string $file) => basename($file, '.jsonl'),
            glob($this->directory().'/*.jsonl') ?: [],
        );

        $months = array_values(array_filter($months, self::isMonth(...)));
        rsort($months);

        return $months;
    }

    /**
     * Every record of $month, oldest first. Unreadable lines are skipped.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function records(string $month): \Generator
    {
        $path = $this->path($month);
        if (! self::isMonth($month) || ! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (is_array($record)) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A fingerprint that changes whenever $month's log grows, so a cached
     * report of it stays valid until the next call is recorded.
     */
    public function version(string $month): string
    {
        $path = $this->path($month);
        clearstatcache(true, $path);

        return is_file($path) ? filesize($path).'-'.filemtime($path) : 'empty';
    }

    /**
     * Delete logs older than the last $keepMonths months (the current month
     * counts as one). Returns the months removed.
     *
     * @return list<string>
     */
    public function prune(int $keepMonths): array
    {
        $oldestKept = now()->startOfMonth()->subMonths(max(1, $keepMonths) - 1)->format('Y-m');
        $removed = [];

        foreach ($this->months() as $month) {
            if ($month < $oldestKept) {
                File::delete($this->path($month));
                $removed[] = $month;
            }
        }

        return $removed;
    }
}
