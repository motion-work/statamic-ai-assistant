<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Statamic\Contracts\Assets\Asset as AssetContract;

/**
 * Validates and applies asset blueprint meta values for alt-text writes.
 */
final class AssetAltMeta
{
    public function __construct(private ConnectorContext $context) {}

    /**
     * @return array<string, mixed>|null
     */
    public function refuseEdit($asset): ?array
    {
        if ($this->context->actor() === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Saving alt text needs a signed-in Control Panel account.'];
        }

        $container = $asset->container();
        if ($this->context->actor()->can('edit', $asset)
            || $this->context->actor()->can('store', [AssetContract::class, $container])) {
            return null;
        }

        return [
            'saved' => false,
            'error' => 'forbidden',
            'message' => 'Your Control Panel role cannot edit metadata on the '.$container->handle().' assets. Nothing was saved.',
        ];
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, string>  $allowed
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: array<string, array{before: string, after: string}>}
     */
    public function plan($asset, array $values, array $allowed): array
    {
        $applied = [];
        $rejected = [];
        $unchanged = [];
        $diff = [];

        foreach ($values as $handle => $value) {
            if (! is_string($handle) || ! in_array($handle, $allowed, true)) {
                $rejected[] = (string) $handle;

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                $rejected[] = $handle;

                continue;
            }

            $before = (string) ($asset->get($handle) ?? '');
            $after = (string) ($value ?? '');

            if ($before === $after) {
                $unchanged[] = $handle;

                continue;
            }

            $applied[] = $handle;
            $diff[$handle] = ['before' => $before, 'after' => $after];
        }

        return [$applied, $rejected, $unchanged, $diff];
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    public function apply($asset, array $values, array $allowed): array
    {
        [$applied, , , $diff] = $this->plan($asset, $values, $allowed);
        foreach ($diff as $handle => $change) {
            $asset->set($handle, $change['after']);
        }

        return $applied;
    }

    /**
     * @param  array<int, string>  $handles
     */
    public function fingerprint($asset, array $handles): string
    {
        $snap = [];
        foreach ($handles as $handle) {
            $snap[$handle] = (string) ($asset->get($handle) ?? '');
        }

        return hash('sha256', (string) json_encode([$asset->containerHandle().'::'.$asset->path(), $snap]));
    }
}
