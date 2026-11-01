<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use DeepL\UsageDetail;
use Illuminate\Support\Facades\Http;

/**
 * The DeepL account's usage for the Control Panel: characters (per API key
 * and for the account), documents, billing period and an estimated cost.
 */
class DeeplUsage
{
    /**
     * DeepL may return very large character_limit / document_limit values (e.g. 1e12) for
     * Pro keys with no hard cap or internal metering; showing that raw limit in the CP is
     * misleading, so treat it like unlimited for UI purposes.
     */
    private const USAGE_LIMIT_EFFECTIVELY_UNLIMITED = 1_000_000_000_000;

    /**
     * Current billing-period DeepL API usage (characters, documents) for display in the CP.
     * Uses GET /v2/usage. Pro API returns account totals plus api_key_character_* for the key used;
     * we prefer key-level numbers for the main meter when present.
     *
     * @return array{
     *   character: ?array,
     *   character_account: ?array,
     *   character_is_api_key_scoped: bool,
     *   document: ?array,
     *   team_document: ?array,
     *   any_limit_reached: bool,
     *   billing: array{period_start?: string, period_end?: string},
     *   estimated_cost: array{
     *     enabled: bool,
     *     base_fee_eur?: float,
     *     per_million_chars_eur?: float,
     *     account_character_count?: int,
     *     estimated_total_eur?: float
     *   }
     * }
     */
    public function getUsageForApi(): array
    {
        $apiKey = config('deepl.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('DeepL API key is not configured. Set DEEPL_API_KEY in your .env file.');
        }

        $body = $this->fetchUsageResponseBody($apiKey);
        $usage = new Usage($body);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $fromApiKey = $this->hasUsagePair($decoded, 'api_key_character_count', 'api_key_character_limit');
        $primaryChar = $fromApiKey ? $this->usagePair($decoded, 'api_key_character_count', 'api_key_character_limit') : null;
        if ($primaryChar === null && $usage->character !== null) {
            $primaryChar = $this->serializeUsageDetail($usage->character);
        }

        $accountChar = $this->hasUsagePair($decoded, 'character_count', 'character_limit')
            ? $this->usagePair($decoded, 'character_count', 'character_limit')
            : null;

        $characterIsApiKeyScoped = $fromApiKey && $accountChar !== null;

        $anyReached = $usage->anyLimitReached();
        if ($primaryChar !== null && ! $primaryChar['unlimited']
            && isset($primaryChar['limit']) && $primaryChar['limit'] !== null
            && $primaryChar['count'] >= $primaryChar['limit']) {
            $anyReached = true;
        }

        return [
            'character' => $primaryChar,
            'character_account' => $characterIsApiKeyScoped ? $accountChar : null,
            'character_is_api_key_scoped' => $characterIsApiKeyScoped,
            'document' => $this->serializeUsageDetail($usage->document),
            'team_document' => $this->serializeUsageDetail($usage->teamDocument),
            'any_limit_reached' => $anyReached,
            'billing' => $this->billingPeriod($decoded),
            'estimated_cost' => $this->estimatedCost($decoded),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function hasUsagePair(array $decoded, string $countKey, string $limitKey): bool
    {
        return isset($decoded[$countKey], $decoded[$limitKey])
            && is_numeric($decoded[$countKey])
            && is_numeric($decoded[$limitKey]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function usagePair(array $decoded, string $countKey, string $limitKey): ?array
    {
        return $this->serializeUsagePair((int) $decoded[$countKey], (int) $decoded[$limitKey]);
    }

    /**
     * Per DeepL: start_time / end_time are only in some Pro responses.
     * https://developers.deepl.com/api-reference/usage-and-quota
     *
     * @param  array<string, mixed>  $decoded
     * @return array{period_end?: string, period_start?: string}
     */
    private function billingPeriod(array $decoded): array
    {
        $billing = [];
        $endRaw = $decoded['end_time'] ?? null;
        $startRaw = $decoded['start_time'] ?? null;
        if (is_string($endRaw) && trim($endRaw) !== '') {
            $billing['period_end'] = trim($endRaw);
        }
        if (is_string($startRaw) && trim($startRaw) !== '') {
            $billing['period_start'] = trim($startRaw);
        }

        return $billing;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function estimatedCost(array $decoded): array
    {
        if (! config('deepl.estimated_cost_enabled', true)
            || ! isset($decoded['character_count'])
            || ! is_numeric($decoded['character_count'])) {
            return ['enabled' => false];
        }

        $accountCount = (int) $decoded['character_count'];
        $base = (float) config('deepl.estimated_monthly_base_fee_eur', 4.99);
        $perM = (float) config('deepl.estimated_per_million_chars_eur', 20);
        $variable = ($accountCount / 1_000_000.0) * $perM;

        return [
            'enabled' => true,
            'base_fee_eur' => $base,
            'per_million_chars_eur' => $perM,
            'account_character_count' => $accountCount,
            'estimated_total_eur' => round($base + $variable, 2),
        ];
    }

    /**
     * Raw JSON body from GET /v2/usage (same host as the DeepL PHP client uses for free vs Pro keys).
     */
    protected function fetchUsageResponseBody(string $apiKey): string
    {
        $baseUrl = Translator::isAuthKeyFreeAccount($apiKey)
            ? TranslatorOptions::DEFAULT_SERVER_URL_FREE
            : TranslatorOptions::DEFAULT_SERVER_URL;

        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key '.$apiKey,
            'User-Agent' => 'MotionWorkStatamicAiAssistant/1.0',
        ])->timeout(20)->get($baseUrl.'/v2/usage');

        if (! $response->successful()) {
            throw new \RuntimeException('DeepL usage request failed (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    private function serializeUsageDetail(?UsageDetail $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        return $this->serializeUsagePair($detail->count, $detail->limit);
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    private function serializeUsagePair(int $count, int $limit): ?array
    {
        if ($limit <= 0 || $limit >= self::USAGE_LIMIT_EFFECTIVELY_UNLIMITED) {
            return [
                'count' => $count,
                'limit' => null,
                'unlimited' => true,
                'percent' => null,
            ];
        }

        return [
            'count' => $count,
            'limit' => $limit,
            'unlimited' => false,
            'percent' => min(100, (int) round(($count / $limit) * 100)),
        ];
    }
}
