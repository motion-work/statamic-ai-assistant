<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

/**
 * CRUD endpoints for the DeepL glossary + style rules CP page. Every CP user
 * can read them; only super admins can change them, because they apply to
 * every translation on the site.
 */
class TranslationGlossaryController
{
    public function __construct(
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private DeeplService $deepl,
    ) {}

    public function data(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        if (! User::current()?->isSuper()) {
            return response()->json(['error' => __('Only super admins can change the glossary and style rules.')], 403);
        }

        $data = $request->validate([
            'entries' => 'sometimes|array',
            'entries.*' => 'array',
            'entries.*.id' => 'nullable|string|max:100',
            'entries.*.terms' => 'present|array',
            'entries.*.terms.*' => 'nullable|string|max:500',
            'styles' => 'sometimes|array',
            // Each language maps to a list of style instructions (a bare string is
            // also accepted for backward compatibility and normalized server-side).
            'styles.*' => 'nullable',
            'styles.*.*' => 'nullable|string|max:2000',
        ]);

        $warnings = [];

        try {
            if ($request->has('entries')) {
                $this->glossary->save($data['entries'] ?? []);
                if ($this->deepl->isConfigured()) {
                    $warnings = array_merge($warnings, $this->glossary->sync());
                }
            }

            if ($request->has('styles')) {
                $this->styleRules->save($data['styles'] ?? []);
                if ($this->deepl->isConfigured()) {
                    $warnings = array_merge($warnings, $this->styleRules->sync());
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to save translation glossary / style rules', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Could not save. Please try again.')], 500);
        }

        return response()->json(array_merge(
            ['success' => true, 'warnings' => array_values($warnings)],
            $this->payload(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $styles = [];

        foreach ($this->styleRules->rules() as $lang => $rule) {
            $styles[$lang] = [
                'instructions' => $rule['instructions'],
                'synced' => $rule['style_id'] !== null,
            ];
        }

        return [
            'languages' => $this->glossary->languages(),
            'entries' => $this->glossary->entries(),
            'glossary_synced' => $this->glossary->glossaryId() !== null,
            'styles' => $styles,
            // Without a DeepL key there is nothing to sync: the AI translation
            // fallback reads these rules straight from the saved file.
            'deepl_configured' => $this->deepl->isConfigured(),
            'can_edit' => (bool) User::current()?->isSuper(),
        ];
    }
}
