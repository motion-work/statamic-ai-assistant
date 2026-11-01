<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Facades\Log;

/**
 * An asset's public URL, or null when it has none: a private container, or a
 * disk that cannot generate URLs (SFTP/FTP throw "does not support retrieving
 * URLs"). Callers list many assets, so a failing disk is logged once per
 * container and process, not once per file.
 */
final class AssetUrl
{
    /** @var array<string, true> */
    private static array $reported = [];

    public static function absolute($asset): ?string
    {
        return self::resolve($asset, fn () => $asset->absoluteUrl());
    }

    public static function relative($asset): ?string
    {
        return self::resolve($asset, fn () => $asset->url());
    }

    private static function resolve($asset, callable $url): ?string
    {
        try {
            $value = $url();
        } catch (\Throwable $e) {
            $container = (string) $asset->containerHandle();

            if (! isset(self::$reported[$container])) {
                self::$reported[$container] = true;
                Log::warning('[assets] container has no public URLs', ['container' => $container, 'error' => $e->getMessage()]);
            }

            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
