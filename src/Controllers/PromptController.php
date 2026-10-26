<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Services\GroqService;
use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Statamic\Facades\Site;

class PromptController
{
    private $provider;

    public function __construct()
    {
        $this->provider = config('statamic-ai-assistant.provider_name');
    }

    /**
     * Get the correct AI service instance based on configuration.
     *
     * @return mixed
     */
    private function getAiService()
    {
        if ($this->provider === 'infomaniak') {
            return new InfomaniakService();
        }
        return new GroqService();
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
        ]);
    
        $aiService = $this->getAiService();
        $content = $aiService->generateContentFromPrompt($data['title']);
    
        return response()->json(['content' => $content]);
    }
    
    public function handleRefactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'   => 'required|string',
            'prompt' => 'required|string',
        ]);
    
        $aiService = $this->getAiService();
        $content = $aiService->generateRefactorFromPrompt($data['text'], $data['prompt']);
    
        return response()->json(['content' => $content]);
    }
    
    public function handleHtmlRefactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'   => 'required|string',
            'prompt' => 'required|string',
        ]);
    
        $aiService = $this->getAiService();
        $content = $aiService->generateHtmlRefactorFromPrompt($data['text'], $data['prompt']);
    
        return response()->json(['content' => $content]);
    }
}
