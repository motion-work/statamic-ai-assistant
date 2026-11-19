<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\Client\Response;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleClient;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleService;
use Statamic\Facades\User;

/**
 * mateo's AI gateway: sites built by mateo ask Claude through mateo, which picks the
 * model and effort per task and meters every call per site and control panel user.
 * The task travels in the model field (`mateo-agent`, `mateo-field` …); the site is
 * known by its token, the user by the `X-Mateo-User` header.
 */
class MateoService extends OpenAiCompatibleService
{
    public const MODEL_PREFIX = 'mateo-';

    protected function providerName(): string
    {
        return 'mateo';
    }

    protected function providerLabel(): string
    {
        return 'mateo';
    }

    protected function client(): OpenAiCompatibleClient
    {
        $user = $this->editorEmail();

        return new OpenAiCompatibleClient(
            rtrim((string) config('statamic-ai-assistant.mateo_gateway_url'), '/').'/chat/completions',
            (string) config('statamic-ai-assistant.mateo_gateway_token'),
            (int) config('statamic-ai-assistant.mateo_http_timeout', 300),
            $user === null ? [] : ['X-Mateo-User' => $user],
        );
    }

    /**
     * The CP user's e-mail: the signed-in one, else (in a queued agent or
     * translation job, where nobody is signed in) the user the job runs for.
     */
    private function editorEmail(): ?string
    {
        $userId = $this->completionContext()['user_id'] ?? null;
        $user = User::current() ?? (is_string($userId) && $userId !== '' ? User::find($userId) : null);
        $email = $user?->email();

        return is_string($email) && $email !== '' ? $email : null;
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
        return ['model' => self::MODEL_PREFIX.$task->value];
    }

    protected function failureException(Response $response): \RuntimeException
    {
        $hint = $response->json('error.message') ?? mb_substr($response->body(), 0, 500);

        return new \RuntimeException(match ($response->status()) {
            402 => __('The AI budget for this month is used up.'),
            401 => __('mateo does not know this website. Check MATEO_AI_GATEWAY_TOKEN.'),
            default => __('mateo request failed (:status): :hint', ['status' => $response->status(), 'hint' => $hint]),
        });
    }

    protected function emptyReplyMessage(): string
    {
        return __('The AI returned no text.');
    }
}
