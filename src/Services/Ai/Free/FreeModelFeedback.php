<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\Free;

use Illuminate\Http\Client\Response;

/**
 * Learns from free-mode calls which models to skip (FreeModelHealth).
 *
 * After a success, the model that answered is healthy again, and every model
 * listed before it was passed over by OpenRouter's fallback (busy, down, or
 * blocked by the privacy setting), so it is moved down for a while. After a
 * failure, all listed models are: for a privacy block only under that policy.
 * The router itself (openrouter/free) is never demoted: it is the last resort.
 */
final class FreeModelFeedback
{
    public function __construct(private readonly FreeModelHealth $health) {}

    /**
     * @param  list<string>  $requested
     */
    public function succeeded(array $requested, ?string $answeredBy): void
    {
        foreach ($requested as $model) {
            if ($model === $answeredBy) {
                $this->health->ok($model);

                return;
            }
            if ($model !== FreeModelPicker::ROUTER) {
                $this->health->busy($model);
            }
        }
    }

    /**
     * @param  list<string>  $requested
     */
    public function failed(array $requested, Response $response, string $policy): void
    {
        $blocked = $response->status() === 404 && self::isPolicyBlock($response);

        if (! $blocked && $response->status() !== 429 && $response->status() < 500) {
            return;
        }

        foreach ($requested as $model) {
            if ($model === FreeModelPicker::ROUTER) {
                continue;
            }
            $blocked ? $this->health->blocked($model, $policy) : $this->health->busy($model);
        }
    }

    /** OpenRouter's "No endpoints found matching your data policy". */
    public static function isPolicyBlock(Response $response): bool
    {
        return str_contains(strtolower((string) $response->json('error.message')), 'data policy');
    }
}
