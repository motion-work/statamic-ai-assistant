<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\Client\Response;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\ChatCompletionsTransport;
use MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli\ClaudeCliClient;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleService;

/**
 * Local development on the Claude Code CLI (`provider_name=claude-cli`): every
 * text task runs through `claude -p` on the subscription the CLI is signed in
 * with, so working on a site locally spends no API credits. The agent task
 * uses `claude_cli_model`, the quick tasks `claude_cli_model_fast`. Tool
 * calls and structured output travel as JSON (ClaudeCliPrompt). Never picked
 * by auto, and refused in production.
 */
class ClaudeCliService extends OpenAiCompatibleService
{
    protected function providerName(): string
    {
        return 'claude-cli';
    }

    protected function providerLabel(): string
    {
        return 'Claude CLI';
    }

    protected function client(): ChatCompletionsTransport
    {
        return new ClaudeCliClient;
    }

    protected function requestNamesModels(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return true;
    }

    protected function modelFields(AiTask $task): array
    {
        $agent = $task === AiTask::Agent && ! $this->thinkingDisabled();

        return [
            'model' => (string) config($agent ? 'statamic-ai-assistant.claude_cli_model' : 'statamic-ai-assistant.claude_cli_model_fast', $agent ? 'sonnet' : 'haiku'),
            'effort' => $agent ? (string) config('statamic-ai-assistant.claude_cli_effort', 'medium') : 'low',
        ];
    }

    protected function failureException(Response $response): \RuntimeException
    {
        return new \RuntimeException(__('Claude CLI request failed (:status): :hint', [
            'status' => $response->status(),
            'hint' => $response->json('error.message') ?? mb_substr($response->body(), 0, 500),
        ]));
    }

    protected function emptyReplyMessage(): string
    {
        return __('The AI returned no text.');
    }
}
