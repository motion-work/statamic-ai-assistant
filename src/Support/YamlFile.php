<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\YAML;

/**
 * Reads the addon's own YAML stores (access, set hints, glossary, style rules,
 * Figma tokens). A missing, empty or broken file reads as no data, so a hand
 * edit gone wrong degrades to defaults instead of breaking the CP; a parse
 * error is logged so it can be found.
 */
final class YamlFile
{
    /**
     * @return array<mixed>
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return [];
        }

        try {
            $parsed = YAML::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('Failed to parse '.basename($path), ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }
}
