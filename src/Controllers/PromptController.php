<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Statamic\Facades\Site;

class PromptController
{
    public function __construct(private AbstractAiService $ai)
    {
    }

    public function getLocalizations(): JsonResponse
    {
        $enableTranslations = config('statamic-ai-assistant.translations', true);

        if (!$enableTranslations) {
            return response()->json(new \stdClass());
        }

        return response()->json(['content' => Site::all()]);
    }

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string',
            ...$this->contextRules(),
        ]);

        $content = $this->ai->generateContentFromPrompt($data['title'], $data['context'] ?? []);

        return response()->json(['content' => $content]);
    }

    public function handleRefactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'   => 'required|string',
            'prompt' => 'required|string',
            ...$this->contextRules(),
        ]);

        $content = $this->ai->generateRefactorFromPrompt($data['text'], $data['prompt'], $data['context'] ?? []);

        return response()->json(['content' => $content]);
    }

    /**
     * Refactor HTML (Bard). Accepts either one `text`, or several `texts` — the
     * text runs between Bard sets, so sets never leave the browser — and then
     * answers with `contents` in the same order.
     */
    public function handleHtmlRefactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'    => 'required_without:texts|string',
            'texts'   => 'required_without:text|array|min:1|max:50',
            'texts.*' => 'string',
            'prompt'  => 'required|string',
            ...$this->contextRules(),
        ]);

        $context = $data['context'] ?? [];

        if (isset($data['texts'])) {
            $contents = array_map(
                fn (string $text) => trim(strip_tags($text)) === ''
                    ? $text
                    : $this->ai->generateHtmlRefactorFromPrompt($text, $data['prompt'], $context),
                array_values($data['texts']),
            );

            return response()->json(['contents' => $contents]);
        }

        $content = $this->ai->generateHtmlRefactorFromPrompt($data['text'], $data['prompt'], $context);

        return response()->json(['content' => $content]);
    }

    /**
     * Where the text goes (field, entry, word limit), see
     * AbstractAiService::fieldContextInstructions().
     *
     * @return array<string, string>
     */
    private function contextRules(): array
    {
        return [
            'context' => 'sometimes|array',
            'context.field_label' => 'nullable|string|max:200',
            'context.field_handle' => 'nullable|string|max:200',
            'context.entry_title' => 'nullable|string|max:500',
            'context.max_words' => 'nullable|integer|min:1|max:2000',
        ];
    }
}
