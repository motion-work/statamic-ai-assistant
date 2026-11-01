<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Decodes the JSON arguments of a model's tool call. Every tool (and the
 * planner's stateful job tools) reads its arguments the same way, so a
 * malformed call is logged once here and answered with the same error.
 */
final class ToolArguments
{
    /**
     * @return array<string, mixed>|null null when the JSON is malformed or not an object
     */
    public static function decode(string $json, string $tool): ?array
    {
        try {
            $args = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[chat-tool] invalid arguments JSON', ['tool' => $tool, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($args)) {
            Log::warning('[chat-tool] arguments are not an object', ['tool' => $tool]);

            return null;
        }

        return $args;
    }

    /**
     * The result the model sees for a call decode() rejected.
     *
     * @return array{ok: false, error: string}
     */
    public static function invalid(): array
    {
        return ['ok' => false, 'error' => 'invalid_arguments_json'];
    }
}
