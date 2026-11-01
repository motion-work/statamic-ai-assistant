<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Controllers\Concerns\ReadsAttachments;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntryWriter;
use Statamic\Facades\Site;

class EntryGeneratorController
{
    use ReadsAttachments;

    private EntryGeneratorService $generator;

    private AbstractAiService $aiService;

    private DocumentTextExtractor $documents;

    private EntryWriter $writer;

    public function __construct(
        EntryGeneratorService $generator,
        AbstractAiService $aiService,
        DocumentTextExtractor $documents,
        EntryWriter $writer,
    ) {
        $this->generator = $generator;
        $this->aiService = $aiService;
        $this->documents = $documents;
        $this->writer = $writer;
    }

    /**
     * Generate content from a prompt (does NOT create the entry yet).
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:3',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->json(['error' => __('Please select a collection.')], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        try {
            if ($autoResolve) {
                $resolved = $this->generator->resolveTargetFromPrompt(
                    $data['prompt'],
                    $attachmentContent,
                );
                $collectionHandle = $resolved['collection'];
                $blueprintHandle = $resolved['blueprint'];
            } else {
                $collectionHandle = (string) $request->input('collection');
                $blueprintHandle = (string) ($request->input('blueprint') ?? '');
            }

            $result = $this->generator->generateContent(
                $collectionHandle,
                $blueprintHandle,
                $data['prompt'],
                $locale,
                $attachmentContent,
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'displayData' => $result['displayData'],
                'warnings' => $result['warnings'],
                'resolved_collection' => $collectionHandle,
                'resolved_blueprint' => $blueprintHandle,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Entry generation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => __('Entry generation failed. Please try again.')], 500);
        }
    }

    /**
     * Persist generated data: creates a new entry, or — when `entry_id` is
     * supplied — patches that existing entry via the dedicated update path.
     */
    public function createEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required_without:entry_id|string',
            'blueprint' => 'nullable|string',
            'data' => 'present|array',
            'entry_id' => 'nullable|string',
        ]);

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $entryId = isset($data['entry_id']) && is_string($data['entry_id']) && $data['entry_id'] !== ''
            ? $data['entry_id']
            : null;

        if ($data['data'] === []) {
            return response()->json([
                'error' => $entryId !== null
                    ? __('The AI did not produce any field changes. Try a more specific prompt.')
                    : __('No content to save.'),
            ], 422);
        }

        try {
            if ($entryId !== null) {
                $entry = $this->writer->update($entryId, $data['data']);
                $operation = 'updated';
            } else {
                $entry = $this->writer->create(
                    $data['collection'],
                    $data['blueprint'] ?? '',
                    $locale,
                    $data['data'],
                );
                $operation = 'created';
            }

            return response()->json([
                'success' => true,
                'operation' => $operation,
                'entry_id' => $entry->id(),
                'edit_url' => $entry->editUrl(),
                'title' => $entry->value('title') ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('Entry persist failed', ['error' => $e->getMessage(), 'entry_id' => $entryId]);

            return response()->json(['error' => __('Saving the entry failed. Please try again.')], 500);
        }
    }

    /**
     * Regenerate a single field value.
     */
    public function regenerateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field_type' => 'required|string',
            'current_value' => 'nullable|string',
            'prompt' => 'required|string',
        ]);

        try {
            if ($data['field_type'] === 'bard') {
                $content = $this->aiService->generateHtmlRefactorFromPrompt(
                    $data['current_value'] ?? '',
                    $data['prompt'],
                );
            } else {
                $content = $this->aiService->generateContentFromPrompt($data['prompt']);
            }

            return response()->json(['value' => $content]);
        } catch (\Exception $e) {
            Log::error('Field regeneration failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Field regeneration failed. Please try again.')], 500);
        }
    }
}
