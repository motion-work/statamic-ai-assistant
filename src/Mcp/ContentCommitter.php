<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Git;

/**
 * Queue the same content commit the Control Panel queues after a save.
 * Quiet saves skip that event, so a production deploy would otherwise drop
 * the translation. No-ops where Statamic git automation is off.
 */
class ContentCommitter
{
    public function commit(string $message, ?UserContract $user = null): void
    {
        if (! config('statamic.git.enabled') || ! config('statamic.git.automatic', true)) {
            return;
        }

        try {
            Git::as($user)->dispatchCommit($this->singleLine($message));
        } catch (\Throwable $e) {
            Log::warning('statamic-ai-assistant could not queue the translation commit.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function singleLine(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        return $message !== '' ? $message : 'Translation saved';
    }
}
