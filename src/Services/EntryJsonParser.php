<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\JsonObjectExtractor;
use MotionWork\StatamicAiAssistant\Support\JsonRepair;
use Statamic\Facades\Entry;

/**
 * Reads the entry JSON out of the model's reply, repairing common defects,
 * and asks the model once to fix a reply that still does not parse.
 */
final class EntryJsonParser
{
    public function __construct(private AbstractAiService $aiService) {}

    /**
     * Parse the LLM response, extracting JSON even if wrapped in markdown fences.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);

        // Strip markdown code fences
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $jsonStr = JsonObjectExtractor::firstObject($response);

        if ($jsonStr === null) {
            $firstBrace = strpos($response, '{');
            $lastBrace = strrpos($response, '}');

            if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
                $this->logUnparseableResponse('generation', $rawResponse);
                throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
            }

            $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Cheap local repair of common mid-tier defects (stray unescaped
            // quotes, trailing commas, truncated tail) BEFORE paying for an LLM
            // correction round. Re-validated below, so a bad repair costs nothing.
            $repaired = JsonRepair::repair($response);
            $repairedObject = JsonObjectExtractor::firstObject($repaired) ?? $repaired;
            $decoded = json_decode($repairedObject, true);

            if (is_array($decoded)) {
                Log::info('[entry-gen] recovered malformed JSON via local repair (no LLM retry needed)', [
                    'error' => $e->getMessage(),
                ]);
            } else {
                $this->logUnparseableResponse('generation', $rawResponse, $e->getMessage());
                throw new \RuntimeException(__('Invalid JSON in AI response: :message', ['message' => $e->getMessage()]), $e->getCode(), $e);
            }
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->logUnparseableResponse('generation', $rawResponse, 'response was not a JSON object');
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }

    /**
     * Parse the AI response, retrying ONCE with a strict-JSON correction when the
     * first reply is not valid JSON. Mid-tier models frequently emit malformed
     * JSON (unbalanced brackets) for deep nested Bard/replicator structures; a
     * single corrective round usually recovers instead of failing the whole entry.
     *
     * @param  array<int, array<string, mixed>>  $messages  The messages that produced $rawResponse.
     * @return array<string, mixed>
     */
    public function parseResponseOrRetry(string $rawResponse, array $messages, int $maxTokens, ?callable $onStreamToken = null): array
    {
        try {
            return $this->parseResponse($rawResponse);
        } catch (\RuntimeException $e) {
            Log::warning('[entry-gen] invalid JSON from model; retrying once with a strict-JSON instruction', [
                'error' => $e->getMessage(),
            ]);

            $retryMessages = array_merge($messages, [
                ['role' => 'assistant', 'content' => Str::limit($rawResponse, 12000)],
                ['role' => 'user', 'content' => 'Your previous reply could not be parsed as JSON ('.$e->getMessage().'). '
                    .'Return the SAME content again as ONE strictly valid, minified JSON object and nothing else — '
                    .'no markdown fences, no comments, no trailing commas, and every "{" and "[" must be properly closed. '
                    .'For rich-text (Bard) fields, return the value as an HTML string rather than nested node objects.',
                ],
            ]);

            $retryRaw = $this->aiService->generateFromMessages($retryMessages, $maxTokens, $onStreamToken);

            if ($retryRaw === null || $retryRaw === '') {
                throw $e;
            }

            return $this->parseResponse($retryRaw);
        }
    }

    /**
     * Log the raw AI response when JSON parsing fails, so a future failure can be
     * diagnosed from the logs (the model output is otherwise lost). The response
     * is capped to keep log lines manageable.
     */
    public function logUnparseableResponse(string $stage, string $rawResponse, ?string $error = null): void
    {
        Log::error('[entry-gen] could not parse AI JSON response', [
            'stage' => $stage,
            'error' => $error,
            'length' => strlen($rawResponse),
            'raw_response' => Str::limit($rawResponse, 20000),
        ]);
    }
}
