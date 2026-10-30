<?php

namespace MotionWork\StatamicAiAssistant\Http\Controllers;

use MotionWork\StatamicAiAssistant\Mcp\ImageUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Statamic\Facades\User;

/**
 * The upload link from prepare_image_upload: a page to drop one image on, and
 * the endpoint it (or curl) posts to. The link itself is the credential.
 */
class McpUploadController extends Controller
{
    public function __construct(private ImageUploads $uploads) {}

    public function form(string $token): Response
    {
        $record = $this->uploads->find($token);
        $this->useLocaleOf($record);

        return response()->view('statamic-ai-assistant::mcp-upload', [
            'site' => (string) config('app.name'),
            'record' => $record,
            'maxMegabytes' => round($this->uploads->maxBytes() / 1048576, 1),
        ], $record === null ? 404 : 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $result = $this->uploads->receive($token, $request->file('file'));
        $status = match ($result['error'] ?? null) {
            null => 201,
            'expired' => 404,
            'used' => 409,
            'forbidden' => 403,
            default => 422,
        };

        return response()->json($result, $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function useLocaleOf(?array $record): void
    {
        $user = $record !== null ? User::find($record['user_id']) : null;
        $locale = $user?->preferredLocale();
        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }
    }
}
