<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Facades\Asset;

/**
 * Preview and save alt-text metadata the connected model produced.
 */
final class AssetAltConfirmation
{
    public function __construct(
        private ConnectorContext $context,
        private AssetAltMeta $meta,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array<string, mixed>
     */
    public function preview(array $updates): array
    {
        if ($this->context->actor() === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Saving alt text needs a signed-in Control Panel account.'];
        }

        if ($updates === []) {
            return ['saved' => false, 'error' => 'missing_updates', 'message' => 'Pass at least one update with ref and values.'];
        }

        $planned = $this->planUpdates($updates);
        if (isset($planned['error'])) {
            return $planned;
        }

        $token = (string) Str::uuid();
        Cache::put($this->tokenKey($token), [
            'user_id' => (string) $this->context->actor()->id(),
            'updates' => $planned['updates'],
            'fingerprints' => $planned['fingerprints'],
        ], now()->addMinutes(ConnectorContext::TOKEN_TTL_MINUTES));

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => ConnectorContext::TOKEN_TTL_MINUTES,
            'changes' => $planned['changes'],
            'message' => 'Show these alt text changes to the user. Nothing is saved until save_asset_alts is called with this confirmation_token and confirmed true, after they agree.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function save(string $token, bool $confirmed): array
    {
        if (! $confirmed) {
            return [
                'saved' => false,
                'error' => 'not_confirmed',
                'message' => 'Nothing was saved. Call save_asset_alts again with confirmed true only after the user agrees.',
            ];
        }

        $plan = Cache::get($this->tokenKey($token));
        if (! is_array($plan) || $this->context->actor() === null || $plan['user_id'] !== (string) $this->context->actor()->id()) {
            return [
                'saved' => false,
                'error' => 'preview_required',
                'message' => 'Call preview_asset_alts first and reuse its confirmation_token. Nothing was saved.',
            ];
        }

        $saved = [];
        foreach ($plan['updates'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result = $this->saveOne($row, $plan['fingerprints'], $token);
            if (isset($result['error'])) {
                return $result;
            }
            if ($result !== null) {
                $saved[] = $result;
            }
        }

        Cache::forget($this->tokenKey($token));

        return [
            'saved' => true,
            'updated' => $saved,
            'message' => count($saved).' asset(s) updated.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $fingerprints
     * @return array<string, mixed>|null
     */
    private function saveOne(array $row, array $fingerprints, string $token): ?array
    {
        $ref = (string) ($row['ref'] ?? '');
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];
        $asset = $ref !== '' ? Asset::find($ref) : null;
        if (! $asset) {
            Cache::forget($this->tokenKey($token));

            return ['saved' => false, 'error' => 'missing_asset', 'message' => 'Asset '.$ref.' no longer exists. Nothing was saved.'];
        }

        if (($denied = $this->meta->refuseEdit($asset)) !== null) {
            return $denied;
        }

        $handles = array_keys($values);
        $expected = $fingerprints[$ref] ?? null;
        if ($expected !== null && $this->meta->fingerprint($asset, $handles) !== $expected) {
            Cache::forget($this->tokenKey($token));

            return [
                'saved' => false,
                'error' => 'asset_changed',
                'message' => 'Metadata for '.$ref.' changed after the preview. Review the images again and preview anew. Nothing was saved.',
            ];
        }

        $allowed = $asset->container()->blueprint()?->fields()->all()->keys()->all() ?? [];
        $applied = $this->meta->apply($asset, $values, $allowed);
        if ($applied === []) {
            return null;
        }

        $asset->save();

        return ['ref' => $ref, 'applied' => $applied];
    }

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array<string, mixed>
     */
    private function planUpdates(array $updates): array
    {
        $changes = [];
        $fingerprints = [];
        $cachedUpdates = [];

        foreach ($updates as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ref = isset($row['ref']) && is_string($row['ref']) ? trim($row['ref']) : '';
            $values = is_array($row['values'] ?? null) ? $row['values'] : [];
            if ($ref === '' || $values === []) {
                continue;
            }

            $asset = Asset::find($ref);
            if (! $asset) {
                return ['saved' => false, 'error' => 'missing_asset', 'message' => 'Asset "'.$ref.'" not found.'];
            }

            if (($denied = $this->meta->refuseEdit($asset)) !== null) {
                return $denied;
            }

            $allowed = $asset->container()->blueprint()?->fields()->all()->keys()->all() ?? [];
            [$applied, $rejected, $unchanged, $diff] = $this->meta->plan($asset, $values, $allowed);

            if ($applied === [] && $unchanged !== []) {
                continue;
            }

            if ($applied === []) {
                return [
                    'saved' => false,
                    'error' => 'invalid_values',
                    'message' => 'No valid meta fields for '.$ref.'. Available: '.($allowed !== [] ? implode(', ', $allowed) : '(none)'),
                    'rejected' => $rejected,
                ];
            }

            $fingerprints[$ref] = $this->meta->fingerprint($asset, array_keys($diff));
            $cachedUpdates[] = [
                'ref' => $ref,
                'values' => array_map(fn ($d) => $d['after'], $diff),
            ];
            $changes[] = [
                'ref' => $ref,
                'path' => $asset->path(),
                'changes' => $diff,
                'rejected' => $rejected,
            ];
        }

        if ($changes === []) {
            return [
                'saved' => false,
                'error' => 'nothing_to_save',
                'message' => 'Every value matches what the assets already hold, or no valid updates were given.',
            ];
        }

        return [
            'changes' => $changes,
            'fingerprints' => $fingerprints,
            'updates' => $cachedUpdates,
        ];
    }

    private function tokenKey(string $token): string
    {
        return 'mcp_asset_alt:'.$token;
    }
}
