<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use Statamic\Facades\User;

/**
 * Who an AI call was made for, when it runs in a queued job.
 *
 * A queue worker has no signed-in CP user, so jobs capture the user id when
 * they are dispatched from a CP request and run their AI work through run().
 * Agent generation jobs are dispatched by the planner inside the queue, so the
 * planner job remembers the session's owner for them.
 */
final class UsageAttribution
{
    private const OWNER_KEY = 'statamic-ai-assistant.agent-session-owner.';

    /** The signed-in CP user's id, captured when a job is constructed. */
    public static function currentUserId(): ?string
    {
        $id = User::current()?->id();

        return $id !== null ? (string) $id : null;
    }

    public static function rememberSessionOwner(string $sessionId, ?string $userId): void
    {
        if ($userId !== null && $userId !== '') {
            // Agent sessions live in the cache for a day; the owner as long.
            Cache::put(self::OWNER_KEY.$sessionId, $userId, now()->addDay());
        }
    }

    public static function sessionOwner(string $sessionId): ?string
    {
        $owner = Cache::get(self::OWNER_KEY.$sessionId);

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * Run $fn with every AI call it makes attributed to $userId (usage log)
     * and sent to OpenRouter as that user's hashed `user` id.
     *
     * @template T
     *
     * @param  array<string, string|null>  $context  e.g. ['agent_session_id' => '…']
     * @param  callable(): T  $fn
     * @return T
     */
    public static function run(?string $userId, array $context, callable $fn): mixed
    {
        $ai = app(AbstractAiService::class);
        $context = array_filter(
            ['user_id' => $userId, ...$context],
            fn ($value) => $value !== null && $value !== '',
        );

        return $ai->withCompletionContext($context, function () use ($ai, $userId, $fn) {
            if ($userId === null || $userId === '') {
                return $fn();
            }

            return $ai->withRequestOptions(['user' => OpenRouterService::userHashFor($userId)], $fn);
        });
    }
}
