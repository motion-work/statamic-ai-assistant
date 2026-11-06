<?php

namespace MotionWork\StatamicAiAssistant\Usage;

/**
 * Adds up usage records: totals, breakdowns by task, model, user and day,
 * and every call (newest first) for the paged call list. One pass over a month's log.
 */
final class UsageTally
{
    /** @var array<string, int|float> */
    private array $totals;

    /** @var array<string, array<string, array<string, int|float>>> */
    private array $groups = ['task' => [], 'model' => [], 'user' => [], 'day' => []];

    /** @var list<array<string, mixed>> */
    private array $recent = [];

    public function __construct()
    {
        $this->totals = self::emptyBucket() + ['fallbacks' => 0, 'priced_calls' => 0];
    }

    /** @param  array<string, mixed>  $record */
    public function add(array $record): void
    {
        $this->addTo($this->totals, $record);
        $this->totals['fallbacks'] += ! empty($record['fallback']) ? 1 : 0;
        $this->totals['priced_calls'] += is_numeric($record['cost'] ?? null) ? 1 : 0;

        $keys = [
            'task' => (string) ($record['task'] ?? 'agent'),
            'model' => (string) ($record['model'] ?? '') ?: '—',
            'user' => (string) ($record['user_id'] ?? ''),
            'day' => substr((string) ($record['ts'] ?? ''), 0, 10),
        ];

        foreach ($keys as $group => $key) {
            $this->groups[$group][$key] ??= self::emptyBucket();
            $this->addTo($this->groups[$group][$key], $record);
        }

        $this->recent[] = $record;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $calls = (int) $this->totals['calls'];

        return [
            'totals' => $this->totals + [
                'fallback_rate' => $calls > 0 ? $this->totals['fallbacks'] / $calls : 0.0,
            ],
            'by_task' => $this->rows('task'),
            'by_model' => $this->rows('model'),
            'by_user' => $this->rows('user'),
            'by_day' => $this->days(),
            'recent' => array_reverse($this->recent),
        ];
    }

    /**
     * One group as rows, most expensive first (then most calls).
     *
     * @return list<array<string, int|float|string>>
     */
    private function rows(string $group): array
    {
        $rows = [];
        foreach ($this->groups[$group] as $key => $bucket) {
            $rows[] = ['key' => (string) $key] + $bucket;
        }

        usort($rows, fn (array $a, array $b) => [$b['cost'], $b['calls']] <=> [$a['cost'], $a['calls']]);

        return $rows;
    }

    /** @return list<array<string, int|float|string>> Days in calendar order. */
    private function days(): array
    {
        $days = $this->groups['day'];
        ksort($days);

        $rows = [];
        foreach ($days as $day => $bucket) {
            $rows[] = ['key' => (string) $day] + $bucket;
        }

        return $rows;
    }

    /**
     * @param  array<string, int|float>  $bucket
     * @param  array<string, mixed>  $record
     */
    private function addTo(array &$bucket, array $record): void
    {
        $bucket['calls']++;
        $bucket['cost'] += is_numeric($record['cost'] ?? null) ? (float) $record['cost'] : 0.0;
        $bucket['prompt_tokens'] += (int) ($record['prompt_tokens'] ?? 0);
        $bucket['completion_tokens'] += (int) ($record['completion_tokens'] ?? 0);
        $bucket['cached_tokens'] += (int) ($record['cached_tokens'] ?? 0);
        $bucket['reasoning_tokens'] += (int) ($record['reasoning_tokens'] ?? 0);
        $bucket['web_searches'] += (int) ($record['web_searches'] ?? 0);
        $bucket['duration_ms'] += (int) ($record['duration_ms'] ?? 0);
    }

    /** @return array<string, int|float> */
    private static function emptyBucket(): array
    {
        return [
            'calls' => 0,
            'cost' => 0.0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cached_tokens' => 0,
            'reasoning_tokens' => 0,
            'web_searches' => 0,
            'duration_ms' => 0,
        ];
    }
}
