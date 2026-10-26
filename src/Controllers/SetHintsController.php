<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\SetHintsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SetHintsController
{
    private SetHintsService $hints;

    private AbstractAiService $ai;

    public function __construct(SetHintsService $hints, AbstractAiService $ai)
    {
        $this->hints = $hints;
        $this->ai = $ai;
    }

    /**
     * Return the discovered set + field lists with existing hints.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'sets' => $this->hints->discoverSets(),
            'fields' => $this->hints->discoverFields(),
        ]);
    }

    /**
     * Persist the submitted hint maps (block hints and/or field hints).
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hints' => 'sometimes|array',
            'hints.*' => 'array',
            'hints.*.ai_description' => 'nullable|string|max:4000',
            'hints.*.when_to_use' => 'nullable|array',
            'hints.*.when_to_use.*' => 'nullable|string|max:500',
            'field_hints' => 'sometimes|array',
            'field_hints.*' => 'array',
            'field_hints.*.ai_description' => 'nullable|string|max:4000',
            'field_hints.*.when_to_use' => 'nullable|array',
            'field_hints.*.when_to_use.*' => 'nullable|string|max:500',
        ]);

        try {
            if ($request->has('hints')) {
                $this->hints->save($data['hints'] ?? []);
            }

            if ($request->has('field_hints')) {
                $this->hints->saveFieldHints($data['field_hints'] ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to save set hints', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Could not save hints.')], 500);
        }

        return response()->json([
            'success' => true,
            'sets' => $this->hints->discoverSets(),
            'fields' => $this->hints->discoverFields(),
        ]);
    }

    /**
     * Ask the AI to draft an ai_description + 3-6 when_to_use tips
     * for a given set, based on its anatomy and where it's used.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handle' => 'required|string|max:200',
            'kind' => 'sometimes|string|in:set,field',
        ]);

        $kind = (string) ($data['kind'] ?? 'set');

        if ($kind === 'field') {
            $context = $this->hints->collectFieldContext((string) $data['handle']);

            if ($context === null) {
                return response()->json(['error' => __('Field not found in any blueprint.')], 404);
            }

            $messages = $this->buildFieldGeneratePromptMessages($context);
        } else {
            $context = $this->hints->collectSetContext((string) $data['handle']);

            if ($context === null) {
                return response()->json(['error' => __('Block not found in any blueprint.')], 404);
            }

            $messages = $this->buildGeneratePromptMessages($context);
        }

        try {
            // Lightweight block-hint generation — use the fast model tier.
            $raw = $this->ai->usingFastModel(fn () => $this->ai->generateFromMessages($messages, 700));
        } catch (\Throwable $e) {
            Log::error('AI generate hint failed', ['handle' => $data['handle'], 'error' => $e->getMessage()]);

            return response()->json(['error' => __('The AI request failed. Please try again.')], 502);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return response()->json(['error' => __('The AI returned no content.')], 502);
        }

        try {
            $parsed = $this->parseJsonResponse($raw);
        } catch (\RuntimeException $e) {
            Log::warning('AI hint JSON parse failed', ['raw' => $raw, 'error' => $e->getMessage()]);

            return response()->json(['error' => __('Could not read AI response. Please try again.')], 502);
        }

        $description = isset($parsed['ai_description']) && is_string($parsed['ai_description'])
            ? trim($parsed['ai_description'])
            : '';

        $tips = [];

        if (isset($parsed['when_to_use']) && is_array($parsed['when_to_use'])) {
            foreach ($parsed['when_to_use'] as $tip) {
                if (is_string($tip)) {
                    $t = trim($tip);
                    if ($t !== '') {
                        $tips[] = $t;
                    }
                }
            }
        }

        // Clamp to a sensible size
        $tips = array_slice(array_values(array_unique($tips)), 0, 6);

        if ($description === '' && $tips === []) {
            return response()->json(['error' => __('The AI response did not contain any usable content.')], 502);
        }

        return response()->json([
            'handle' => $context['handle'],
            'ai_description' => $description,
            'when_to_use' => $tips,
        ]);
    }

    /**
     * @param  array{
     *   handle: string,
     *   title: string,
     *   instructions: string,
     *   inner_fields: array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }  $context
     * @return array<int, array{role: string, content: string}>
     */
    private function buildGeneratePromptMessages(array $context): array
    {
        $fields = [];

        foreach ($context['inner_fields'] as $f) {
            $line = '- '.$f['display'].' ('.$f['type'].', handle: '.$f['handle'].')';

            if ($f['instructions'] !== '') {
                $line .= ' — '.$f['instructions'];
            }

            if (! empty($f['options'])) {
                $line .= ' — options: '.implode(', ', $f['options']);
            }

            $fields[] = $line;
        }

        $fieldsBlock = $fields !== [] ? implode("\n", $fields) : '(no introspectable fields)';

        $locationLines = [];

        foreach ($context['locations'] as $loc) {
            $locationLines[] = '- '.$loc['collection'].' › '.$loc['blueprint'].' › '.$loc['field'];
        }

        $locationBlock = $locationLines !== [] ? implode("\n", $locationLines) : '(no known locations)';

        $instructions = $context['instructions'] !== ''
            ? "Author-provided block instructions:\n{$context['instructions']}\n\n"
            : '';

        $system = 'You are helping an editor document a Statamic CMS page-builder block so an AI content generator knows precisely when to reach for it.'
            ."\n\n"
            .'Given the block\'s handle, title, its inner fields, and where it is used, produce:'
            ."\n"
            .'1. A concise "ai_description" — 2 to 4 sentences explaining what this block is, what it looks like on the page, and any structural conventions (approximate length, columns, imagery, etc.). Infer sensibly from the field names and types.'
            ."\n"
            .'2. A "when_to_use" list — 3 to 6 short trigger phrases (ideally 4–10 words each), each describing ONE concrete scenario where this block is the right choice. Phrases must be specific (e.g. "Page introduction immediately after hero"), not generic platitudes ("For content sections").'
            ."\n\n"
            .'Respond ONLY with a JSON object:'
            ."\n"
            .'{"ai_description": "…", "when_to_use": ["…", "…", "…"]}'
            ."\n\n"
            .'Rules:'
            ."\n"
            .'- No markdown fences, no commentary.'
            ."\n"
            .'- ai_description: factual, editorial tone. Do not guess visual styling beyond what field names reveal.'
            ."\n"
            .'- when_to_use items: each item should describe a distinct, non-overlapping scenario.'
            ."\n"
            .'- If the block is ambiguous, stay neutral — do not invent features that are not implied by the fields.';

        $user = "Block handle: \"{$context['handle']}\"\n"
            ."Block display title: \"{$context['title']}\"\n\n"
            .$instructions
            ."Inner fields:\n{$fieldsBlock}\n\n"
            ."Used in:\n{$locationBlock}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  array{
     *   handle: string,
     *   title: string,
     *   type: string,
     *   instructions: string,
     *   options: array<int, string>,
     *   character_limit: ?int,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }  $context
     * @return array<int, array{role: string, content: string}>
     */
    private function buildFieldGeneratePromptMessages(array $context): array
    {
        $locationLines = [];

        foreach ($context['locations'] as $loc) {
            $locationLines[] = '- '.$loc['collection'].' › '.$loc['blueprint'].' › '.$loc['field'];
        }

        $locationBlock = $locationLines !== [] ? implode("\n", $locationLines) : '(no known locations)';

        $details = [];
        $details[] = 'Field type: '.$context['type'];

        if ($context['instructions'] !== '') {
            $details[] = 'Author-provided instructions: '.$context['instructions'];
        }

        if ($context['options'] !== []) {
            $details[] = 'Options: '.implode(', ', $context['options']);
        }

        if ($context['character_limit'] !== null) {
            $details[] = 'Character limit: '.$context['character_limit'];
        }

        $system = 'You are helping an editor document a single Statamic CMS blueprint field so an AI content generator writes exactly the right kind of content into it.'
            ."\n\n"
            .'Given the field\'s handle, type, and where it is used, produce:'
            ."\n"
            .'1. A concise "ai_description" — 1 to 3 sentences explaining what content belongs in this field and how it appears on the page (e.g. "Main page headline rendered large over the hero image"). Infer sensibly from the handle, type, and blueprint context.'
            ."\n"
            .'2. A "when_to_use" list — 2 to 5 short WRITING GUIDELINES for this field (ideally 4–12 words each), such as length limits, tone, or structural rules (e.g. "Keep under 60 characters", "One short benefit-driven sentence, no punctuation at the end").'
            ."\n\n"
            .'Respond ONLY with a JSON object:'
            ."\n"
            .'{"ai_description": "…", "when_to_use": ["…", "…"]}'
            ."\n\n"
            .'Rules:'
            ."\n"
            .'- No markdown fences, no commentary.'
            ."\n"
            .'- Be factual and specific; do not invent rendering details the field name does not imply.'
            ."\n"
            .'- Guidelines must be actionable writing rules, not generic platitudes.';

        $user = "Field handle: \"{$context['handle']}\"\n"
            ."Field display title: \"{$context['title']}\"\n"
            .implode("\n", $details)."\n\n"
            ."Used in:\n{$locationBlock}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Tolerant JSON parser: strips code fences and extracts the first JSON object.
     *
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $raw): array
    {
        $response = trim($raw);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $first = strpos($response, '{');
        $last = strrpos($response, '}');

        if ($first === false || $last === false || $last <= $first) {
            throw new \RuntimeException('No JSON object found in response.');
        }

        $json = substr($response, $first, $last - $first + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Decoded JSON is not an object.');
        }

        return $decoded;
    }
}
