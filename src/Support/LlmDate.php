<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Carbon;

/**
 * Parses a date written by a model (the agent or the Claude connector)
 * tolerantly (adapted from cboxdk/statamic-mcp): Y-m-d, ISO 8601, "Y-m-d H:i",
 * or a split {date, time} object.
 */
final class LlmDate
{
    /**
     * Null when the value is empty or not a date. Callers decide what that
     * means (a warning, or a default date), so null is the whole answer here.
     */
    public static function parse(mixed $value): ?Carbon
    {
        if (is_array($value)) {
            $date = is_string($value['date'] ?? null) ? trim($value['date']) : '';

            if ($date === '') {
                return null;
            }

            $time = is_string($value['time'] ?? null) ? trim($value['time']) : '';
            $value = trim($date.' '.$time);
        }

        $value = is_string($value) ? trim($value) : '';

        // date_parse() runs the parser Carbon::parse() uses, without throwing.
        if ($value === '' || date_parse($value)['error_count'] > 0) {
            return null;
        }

        return Carbon::parse($value);
    }
}
