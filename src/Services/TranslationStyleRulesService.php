<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\YAML;

/**
 * CP-managed DeepL style rules: editors write one or more free-form style
 * instructions per target language (tone, formality, brand wording, …). Each
 * DeepL style rule (v3) is one rule per language holding an array of custom
 * instructions; on save the local list is pushed as those instructions and the
 * stored style_id is attached automatically to every translation request into
 * that language via DeeplService::translateBatch.
 *
 * Sync is reconcile-by-name and self-healing: on every save we look up all DeepL
 * style rules we own (matched by their generated name) and delete every one for
 * a language before (optionally) recreating a single fresh rule. This removes
 * the duplicates/orphans that recreate-on-save accumulated previously, and an
 * empty instruction list deletes the rule entirely.
 *
 * Storage (versionable YAML, default content/statamic-ai-assistant/translation-style-rules.yaml):
 *   rules:
 *     de:
 *       instructions:
 *         - "Formelle Anrede (Sie)."
 *         - "Immer 'ss' statt 'ß'."
 *       style_id: 3f7a…              # from the last successful sync
 *     en:
 *       instructions:
 *         - "Use British spelling."
 *       style_id: 91bc…
 */
class TranslationStyleRulesService
{
    /** @var array<string, array{instructions: array<int, string>, style_id: ?string}>|null */
    private ?array $cache = null;

    public function __construct(private DeeplService $deepl) {}

    public function storagePath(): string
    {
        $path = config('statamic-ai-assistant.translation_style_rules_path');

        if (! is_string($path) || $path === '') {
            return base_path('content/statamic-ai-assistant/translation-style-rules.yaml');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * @return array<string, array{instructions: array<int, string>, style_id: ?string}>
     */
    public function rules(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->storagePath();

        if (! is_file($path)) {
            return $this->cache = [];
        }

        try {
            $raw = (string) file_get_contents($path);
            $parsed = $raw !== '' ? YAML::parse($raw) : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to parse translation-style-rules.yaml', ['error' => $e->getMessage()]);

            return $this->cache = [];
        }

        $rules = [];

        foreach ((array) ($parsed['rules'] ?? []) as $lang => $rule) {
            if (! is_string($lang) || $lang === '' || ! is_array($rule)) {
                continue;
            }

            // Legacy: `instructions` used to be a single string.
            $instructions = $this->normalizeInstructions($rule['instructions'] ?? []);

            $styleId = isset($rule['style_id']) && is_string($rule['style_id']) && trim($rule['style_id']) !== ''
                ? trim($rule['style_id'])
                : null;

            if ($instructions === [] && $styleId === null) {
                continue;
            }

            $rules[strtolower($lang)] = ['instructions' => $instructions, 'style_id' => $styleId];
        }

        return $this->cache = $rules;
    }

    /**
     * Persist the submitted per-language instructions locally (style ids of the
     * previous sync are kept so the next sync can clean up on DeepL). Each value
     * may be an array of instruction strings or a single string (BC).
     *
     * @param  array<string, mixed>  $instructionsByLang
     * @return array<string, array{instructions: array<int, string>, style_id: ?string}>
     */
    public function save(array $instructionsByLang): array
    {
        $existing = $this->rules();
        $rules = [];

        foreach ($instructionsByLang as $lang => $instructions) {
            if (! is_string($lang)) {
                continue;
            }

            $lang = strtolower(trim($lang));

            if ($lang === '') {
                continue;
            }

            $list = $this->normalizeInstructions($instructions);
            $previousStyleId = $existing[$lang]['style_id'] ?? null;

            // Keep the entry when it has content OR a tracked id that sync must delete.
            if ($list === [] && $previousStyleId === null) {
                continue;
            }

            $rules[$lang] = ['instructions' => $list, 'style_id' => $previousStyleId];
        }

        ksort($rules);

        $this->write($rules);

        return $rules;
    }

    /**
     * Reconcile local style rules with DeepL:
     *  - delete every DeepL rule we own for a language (tracked id + any
     *    duplicates/orphans discovered by name) so nothing lingers;
     *  - recreate a single fresh rule for languages that still have instructions;
     *  - drop languages whose instructions were cleared.
     *
     * Creation happens before deletion of the old ids so a failed create never
     * leaves a language with no active rule. Returns warnings instead of
     * throwing so local edits are never lost.
     *
     * @return array<int, string> warnings
     */
    public function sync(): array
    {
        $rules = $this->rules();
        $warnings = [];

        // Managed DeepL rules grouped by language (for duplicate/orphan cleanup).
        $managedByLang = [];

        try {
            foreach ($this->deepl->listStyleRulesOnDeepL() as $remote) {
                if ($this->isManagedName($remote['name'])) {
                    $managedByLang[$remote['language']][] = $remote['style_id'];
                }
            }
        } catch (\Throwable $e) {
            // Reconciliation is best-effort; fall back to tracked ids only.
            Log::warning('[deepl-style-rules] could not list style rules for reconciliation', ['message' => $e->getMessage()]);
        }

        // Process every language we hold local state for OR still own on DeepL.
        $languages = array_values(array_unique(array_merge(array_keys($rules), array_keys($managedByLang))));

        foreach ($languages as $lang) {
            $rule = $rules[$lang] ?? ['instructions' => [], 'style_id' => null];
            $desired = $rule['instructions'];

            // Every stale DeepL id for this language: the tracked one plus any
            // managed duplicates/orphans found on the account.
            $staleIds = $managedByLang[$lang] ?? [];

            if ($rule['style_id'] !== null) {
                $staleIds[] = $rule['style_id'];
            }

            $staleIds = array_values(array_unique($staleIds));

            try {
                $newId = null;

                if ($desired !== []) {
                    $newId = $this->deepl->createStyleRuleOnDeepL($this->styleRuleName($lang), $lang, $desired);
                }

                foreach ($staleIds as $id) {
                    if ($id !== $newId) {
                        $this->deepl->deleteStyleRuleOnDeepL($id);
                    }
                }

                if ($newId !== null) {
                    $rules[$lang] = ['instructions' => $desired, 'style_id' => $newId];
                } else {
                    unset($rules[$lang]);
                }
            } catch (\Throwable $e) {
                Log::warning('[deepl-style-rules] sync failed', ['lang' => $lang, 'message' => $e->getMessage()]);
                $warnings[] = __('Style rules for ":lang" were saved locally but could not be synced to DeepL: :error', [
                    'lang' => $lang,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->write($rules);

        return $warnings;
    }

    /**
     * Style rule id to attach when translating INTO the given base language.
     */
    public function styleIdFor(string $targetBase): ?string
    {
        $rule = $this->rules()[strtolower($targetBase)] ?? null;

        if ($rule === null || $rule['instructions'] === []) {
            return null;
        }

        return $rule['style_id'];
    }

    /**
     * Normalize a submitted/stored value into a clean list of instruction lines.
     *
     * @return array<int, string>
     */
    private function normalizeInstructions(mixed $instructions): array
    {
        if (is_string($instructions)) {
            $instructions = [$instructions];
        }

        if (! is_array($instructions)) {
            return [];
        }

        $out = [];

        foreach ($instructions as $line) {
            if (! is_string($line)) {
                continue;
            }

            $line = trim($line);

            if ($line !== '' && ! in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return array_values($out);
    }

    protected function styleRuleNamePrefix(): string
    {
        return 'Statamic CMS — '.(string) config('app.name', 'site').' (';
    }

    protected function styleRuleName(string $lang): string
    {
        return $this->styleRuleNamePrefix().$lang.')';
    }

    private function isManagedName(string $name): bool
    {
        return str_starts_with($name, $this->styleRuleNamePrefix());
    }

    /**
     * @param  array<string, array{instructions: array<int, string>, style_id: ?string}>  $rules
     */
    private function write(array $rules): void
    {
        $path = $this->storagePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $document = ['rules' => []];

        foreach ($rules as $lang => $rule) {
            if ($rule['instructions'] === [] && $rule['style_id'] === null) {
                continue;
            }

            $entry = ['instructions' => array_values($rule['instructions'])];

            if ($rule['style_id'] !== null) {
                $entry['style_id'] = $rule['style_id'];
            }

            $document['rules'][$lang] = $entry;
        }

        if ($document['rules'] === []) {
            unset($document['rules']);
        }

        file_put_contents($path, YAML::dump($document));

        $this->cache = $rules;
    }
}
