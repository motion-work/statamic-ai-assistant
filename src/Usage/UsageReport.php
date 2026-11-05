<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use Illuminate\Support\Facades\Cache;

/**
 * A month of AI usage, added up for the CP page.
 *
 * The month's log is read in one pass and the result cached under the log's
 * size and mtime, so the report is only rebuilt after a new call is recorded.
 */
final class UsageReport
{
    public function __construct(private readonly UsageLog $log) {}

    /** @return array<string, mixed> */
    public function forMonth(string $month): array
    {
        $key = 'statamic-ai-assistant.usage-report.'.$month.'.'.$this->log->version($month);

        return Cache::remember($key, now()->addDay(), function () use ($month) {
            $tally = new UsageTally;
            foreach ($this->log->records($month) as $record) {
                $tally->add($record);
            }

            return $tally->toArray();
        });
    }
}
