<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

/**
 * Builds a small JPEG for vision models from raw image bytes (never rewrites the asset).
 */
final class AssetReviewThumbnail
{
    private const MAX_EDGE = 768;

    private const MAX_SOURCE_BYTES = 6_000_000;

    /**
     * @return array{mime: string, data: string}|null base64 JPEG payload, or null when skipped
     */
    public function jpegBase64(string $bytes, string $mimeType): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        if (! extension_loaded('gd')) {
            return null;
        }

        if (str_contains(strtolower($mimeType), 'svg') || str_starts_with(ltrim($bytes), '<')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            imagedestroy($source);

            return null;
        }

        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetW, $targetH);
        if ($target === false) {
            imagedestroy($source);

            return null;
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imagedestroy($source);

        ob_start();
        $ok = imagejpeg($target, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($target);

        if (! $ok || ! is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return ['mime' => 'image/jpeg', 'data' => base64_encode($jpeg)];
    }
}
