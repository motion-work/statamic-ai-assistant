<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * OpenRouter's images endpoint (POST /images): one image per request, base64
 * only (`data[].b64_json` + `media_type`).
 *
 * The endpoint takes a single `model`, not a `models` fallback list, so the
 * fallback happens here: on an error the next model is tried, except when the
 * account or key itself refused (401 key, 402 credits, 403 budget/moderation),
 * where another model would be refused the same way. Each model's own
 * parameter limits also lead to the next model: FLUX, for example, answers
 * 400 to output_format "webp" (tested live, Nov 2026).
 */
final class OpenRouterImages
{
    /** Statuses another model cannot fix. */
    private const ACCOUNT_REFUSALS = [401, 402, 403];

    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    /**
     * @param  list<string>  $models  In fallback order.
     * @param  array<string, mixed>  $params  aspect_ratio, resolution, output_format, user, provider
     * @return array{image: GeneratedImage, generation_id: ?string, usage: array<string, mixed>}
     */
    public function generate(array $models, string $prompt, array $params): array
    {
        $lastError = null;

        foreach ($models as $model) {
            $response = $this->client->send(['model' => $model, 'prompt' => $prompt, 'n' => 1, ...$params]);

            if (! $response->successful()) {
                $lastError = OpenRouterErrors::exception($response);
                if (in_array($response->status(), self::ACCOUNT_REFUSALS, true)) {
                    throw $lastError;
                }
                Log::notice('OpenRouter image model failed, trying the next one', ['model' => $model, 'status' => $response->status()]);

                continue;
            }

            $result = $this->decode($response, $model);
            if ($result !== null) {
                return $result;
            }

            $lastError = new \RuntimeException(__('The image model returned no image.'));
        }

        throw $lastError ?? new \RuntimeException(__('No OpenRouter model is configured for the :task task.', ['task' => AiTask::Image->value]));
    }

    /**
     * @return array{image: GeneratedImage, generation_id: ?string, usage: array<string, mixed>}|null
     */
    private function decode(Response $response, string $model): ?array
    {
        $item = $response->json('data.0');
        $bytes = is_array($item) && is_string($item['b64_json'] ?? null)
            ? base64_decode($item['b64_json'], true)
            : false;

        if ($bytes === false || $bytes === '') {
            Log::warning('OpenRouter image response had no image', ['model' => $model]);

            return null;
        }

        $mediaType = is_string($item['media_type'] ?? null) && $item['media_type'] !== '' ? $item['media_type'] : 'image/png';
        $usage = $response->json('usage');

        return [
            'image' => new GeneratedImage($bytes, $mediaType, $model),
            'generation_id' => $response->header('X-Generation-Id') ?: null,
            'usage' => is_array($usage) ? $usage : [],
        ];
    }
}
