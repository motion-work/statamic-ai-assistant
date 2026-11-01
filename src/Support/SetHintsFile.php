<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\YAML;

/**
 * Where the editors' hints file lives: set_hints_path (relative to the
 * project), by default under content/ so it is versioned with the site; a
 * file from the old storage/ location is copied over once.
 */
final class SetHintsFile
{
    /**
     * Absolute path to the YAML file storing hints.
     */
    public function storagePath(): string
    {
        return $this->resolvedStoragePath();
    }

    /**
     * Legacy path used before set_hints_path was configurable.
     */
    public function legacyStoragePath(): string
    {
        return storage_path('app/statamic-ai-assistant/set-hints.yaml');
    }

    private function resolvedStoragePath(): string
    {
        $path = config('statamic-ai-assistant.set_hints_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/set-hints.yaml');
        }

        return $this->toAbsoluteProjectPath($path);
    }

    private function toAbsoluteProjectPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * If the configured file is missing but the legacy storage file exists, copy once.
     */
    public function migrateLegacyIfNeeded(): void
    {
        $path = $this->resolvedStoragePath();
        $legacy = $this->legacyStoragePath();

        if (is_file($path) || ! is_file($legacy)) {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (! @copy($legacy, $path)) {
            Log::warning('Could not migrate set-hints.yaml from legacy storage path.', [
                'from' => $legacy,
                'to' => $path,
            ]);

            return;
        }

        Log::info('Migrated statamic-ai-assistant set-hints.yaml to versioned path.', [
            'path' => $path,
        ]);

        @unlink($legacy);
    }
}
