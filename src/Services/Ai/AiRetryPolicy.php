<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

/**
 * When a provider call is retried, shared by every provider.
 *
 * Transient: rate limiting (429), gateway and upstream errors (500, 502, 503,
 * 504) and dropped connections. Hard timeouts are NOT transient on purpose: the
 * model is simply slow, so retrying only stacks full-length waits and delays the
 * eventual (same) failure. Payment and budget errors (402, 403) are never
 * retried: they only clear when someone tops up credits or a budget resets.
 */
final class AiRetryPolicy
{
    public static function isTransient(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return ! Str::contains(Str::lower($e->getMessage()), ['timed out', 'timeout', 'operation too slow']);
        }

        if ($e instanceof RequestException) {
            return in_array($e->response?->status(), [429, 500, 502, 503, 504], true);
        }

        return false;
    }

    /** Max attempts for a transient provider call (1 = no retry). */
    public static function times(): int
    {
        return max(1, (int) config('statamic-ai-assistant.ai_http_retry_times', 3));
    }

    /** Backoff between attempts (ms): honour Retry-After, else exponential + jitter. */
    public static function sleepMs(): \Closure
    {
        return function (int $attempt, \Throwable $exception): int {
            if ($exception instanceof RequestException) {
                $retryAfter = (int) $exception->response?->header('Retry-After');
                if ($retryAfter > 0) {
                    return min($retryAfter, 30) * 1000;
                }
            }

            return min(8000, (2 ** max(0, $attempt - 1)) * 1000) + random_int(0, 300);
        };
    }

    /** `$when` predicate for Http::retry: retry transient failures only. */
    public static function when(): \Closure
    {
        return fn (\Throwable $e): bool => self::isTransient($e);
    }
}
