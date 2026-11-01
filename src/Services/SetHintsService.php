<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\SetHintsFile;
use MotionWork\StatamicAiAssistant\Support\YamlFile;
use Statamic\Facades\YAML;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Stores and retrieves the AI instructions for replicator / components sets
 * (block hints) and for regular blueprint fields (field hints — hero, lead, …).
 *
 * For each set or field handle the author can configure:
 *   - ai_description : one-paragraph description of what the block/field is
 *   - when_to_use    : array of short trigger phrases describing ideal usage
 *     (for fields these act as writing guidelines, e.g. "max 60 characters")
 *
 * Both are optional. When absent, the entry is omitted from the LLM
 * catalog and the previous behaviour is preserved.
 *
 * Storage: configurable via statamic-ai-assistant.set_hints_path (default:
 * content/statamic-ai-assistant/set-hints.yaml). Legacy storage path is
 * migrated automatically on first read when the configured file is missing.
 *
 * File shape:
 *   hints:
 *     hero:
 *       ai_description: "Large, visually prominent opener …"
 *       when_to_use:
 *         - "Page introduction immediately after hero"
 *         - "Executive summary"
 *   field_hints:
 *     hero_title:
 *       ai_description: "Main page headline shown over the hero image."
 *       when_to_use:
 *         - "Keep under 60 characters"
 *
 * File shape (legacy, still parsed):
 *   hints:
 *     hero: "Use for large visual openers."
 */
class SetHintsService
{
    public function storagePath(): string
    {
        return (new SetHintsFile)->storagePath();
    }

    /** @var array<string, array{ai_description: string, when_to_use: array<int, string>}>|null */
    private ?array $cache = null;

    /** @var array<string, array{ai_description: string, when_to_use: array<int, string>}>|null */
    private ?array $fieldCache = null;

    /**
     * All saved hints, keyed by set handle.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = $this->parseSection('hints');
    }

    /**
     * All saved field hints, keyed by field handle.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    public function allFieldHints(): array
    {
        if ($this->fieldCache !== null) {
            return $this->fieldCache;
        }

        return $this->fieldCache = $this->parseSection('field_hints');
    }

    /**
     * Return the hint structure for a single field handle (or null when none set).
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    public function forField(string $fieldHandle): ?array
    {
        return $this->allFieldHints()[$fieldHandle] ?? null;
    }

    /**
     * Parse one root key of the storage file into normalized hint entries.
     *
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    private function parseSection(string $rootKey): array
    {
        $parsed = $this->readFile();

        $hints = [];

        if (isset($parsed[$rootKey]) && is_array($parsed[$rootKey])) {
            foreach ($parsed[$rootKey] as $handle => $value) {
                if (! is_string($handle) || $handle === '') {
                    continue;
                }

                $normalized = $this->normalizeHint($value);

                if ($normalized !== null) {
                    $hints[$handle] = $normalized;
                }
            }
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        (new SetHintsFile)->migrateLegacyIfNeeded();

        return YamlFile::read((new SetHintsFile)->storagePath());
    }

    /**
     * Return the hint structure for a single set (or null when none set).
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    public function forSet(string $setHandle): ?array
    {
        return $this->all()[$setHandle] ?? null;
    }

    /**
     * Persist the full hint map. Empty entries are removed.
     *
     * Accepts items either as a structured array
     *   ['ai_description' => '…', 'when_to_use' => ['…', '…']]
     * or as a plain string (treated as ai_description only, for legacy calls).
     *
     * @param  array<string, mixed>  $hints
     */
    public function save(array $hints): void
    {
        $this->cache = $this->writeSection('hints', $hints);
    }

    /**
     * Persist the full field-hint map (same normalization as set hints).
     *
     * @param  array<string, mixed>  $hints
     */
    public function saveFieldHints(array $hints): void
    {
        $this->fieldCache = $this->writeSection('field_hints', $hints);
    }

    /**
     * Free-form site-wide agent instructions (brand voice, terminology,
     * do/don'ts) maintained by editors on the AI instructions page and
     * injected into the planner + generator prompts — the site-level analog
     * of a coding agent's project memory file.
     */
    public function siteInstructions(): string
    {
        $value = $this->readFile()['site_instructions'] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Persist the site instructions, preserving the other sections of the file.
     * An empty string removes the key.
     */
    public function saveSiteInstructions(string $instructions): string
    {
        $instructions = trim($instructions);

        $document = $this->readFile();
        $document['site_instructions'] = $instructions;

        if ($instructions === '') {
            unset($document['site_instructions']);
        }

        $path = (new SetHintsFile)->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, YAML::dump($document));

        return $instructions;
    }

    /**
     * Normalize + write one root key while preserving the other sections of the file.
     *
     * @param  array<string, mixed>  $hints
     * @return array<string, array{ai_description: string, when_to_use: array<int, string>}>
     */
    private function writeSection(string $rootKey, array $hints): array
    {
        $clean = [];

        foreach ($hints as $handle => $value) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $normalized = $this->normalizeHint($value);

            if ($normalized !== null) {
                $clean[$handle] = $normalized;
            }
        }

        ksort($clean);

        $document = $this->readFile();
        $document[$rootKey] = $clean;

        if ($clean === []) {
            unset($document[$rootKey]);
        }

        $path = (new SetHintsFile)->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, YAML::dump($document));

        return $clean;
    }

    /**
     * Coerce a hint value into the canonical structure, or return null
     * when the resulting entry would be empty.
     *
     * @return array{ai_description: string, when_to_use: array<int, string>}|null
     */
    private function normalizeHint(mixed $value): ?array
    {
        if (is_string($value)) {
            $desc = trim($value);

            return $desc !== '' ? ['ai_description' => $desc, 'when_to_use' => []] : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $desc = isset($value['ai_description']) && is_string($value['ai_description'])
            ? trim($value['ai_description'])
            : '';

        $tipsRaw = $value['when_to_use'] ?? [];
        $tips = [];

        if (is_array($tipsRaw)) {
            foreach ($tipsRaw as $tip) {
                if (! is_string($tip)) {
                    continue;
                }

                $t = trim($tip);

                if ($t !== '') {
                    $tips[] = $t;
                }
            }
        }

        if ($desc === '' && $tips === []) {
            return null;
        }

        return [
            'ai_description' => $desc,
            'when_to_use' => array_values(array_unique($tips)),
        ];
    }
}
