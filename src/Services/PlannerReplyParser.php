<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;

/**
 * Reads what the planner model actually sent back. Models drift from the tool
 * protocol: they print answer_question / propose_plan / other tool calls as
 * reply text, or wrap the message in provider-specific shapes. This recovers
 * the intent so the planner can act on it instead of failing.
 */
final class PlannerReplyParser
{
    /**
     * Normalize legacy OpenAI `function_call` payloads into `tool_calls`.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>
     */
    public function normalizeAssistantMessage(array $msg): array
    {
        $toolCalls = $msg['tool_calls'] ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            return $msg;
        }

        $fn = $msg['function_call'] ?? null;
        if (! is_array($fn)) {
            return $msg;
        }

        $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
        if ($name === '') {
            return $msg;
        }

        $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';
        $msg['tool_calls'] = [[
            'id' => 'legacy_'.Str::uuid()->toString(),
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $args],
        ]];

        return $msg;
    }

    /**
     * Parse models that print answer_question as assistant text instead of calling the tool.
     */
    public function extractAnswerQuestion(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $json = null;
        if (preg_match('/^answer_question\s*[:(]\s*(\{.*\})\s*\)?$/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/^\{\s*"answer"\s*:/s', $content)) {
            $json = $content;
        }

        if ($json === null) {
            return null;
        }

        // Free text that merely looks like a call is not one: no answer.
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['answer']) || ! is_string($decoded['answer'])) {
            return null;
        }

        $answer = trim($decoded['answer']);

        return $answer !== '' ? $answer : null;
    }

    /**
     * Tool name when the model PRINTED a tool call as reply text
     * ("find_entries: …") instead of invoking it — protocol drift typical of
     * mid-tier models on long prompts. Generic over every registered tool.
     *
     * @param  array<int, string>  $toolNames  Lower-cased registered tool names
     */
    public function printedToolCallName(string $content, array $toolNames): ?string
    {
        if (preg_match('/^\s*([a-z0-9_]+)\s*[:({]/i', trim($content), $m) !== 1) {
            return null;
        }

        $name = strtolower($m[1]);

        return in_array($name, $toolNames, true) ? $name : null;
    }

    /**
     * Recover a propose_plan the model printed as assistant TEXT instead of a
     * tool call ("propose_plan: …" or "propose_plan:{\"steps\":[…]}").
     * Returns the human-readable plan text, or null when the content is not a
     * printed plan.
     */
    public function extractProposedPlan(string $content): ?string
    {
        $content = trim($content);

        if ($content === '' || preg_match('/^propose_plan\s*[:(]?\s*/i', $content, $m) !== 1) {
            return null;
        }

        $rest = trim(substr($content, strlen($m[0])), " \t\n\r)");

        if (str_starts_with($rest, '{')) {
            $decoded = json_decode($rest, true);

            if (is_array($decoded)) {
                $steps = array_values(array_filter((array) ($decoded['steps'] ?? []), 'is_string'));

                if ($steps !== []) {
                    $lines = [];
                    foreach ($steps as $i => $step) {
                        $lines[] = ($i + 1).'. '.$step;
                    }

                    $text = __('Proposed plan:')."\n".implode("\n", $lines);
                    $question = isset($decoded['question']) && is_string($decoded['question']) ? trim($decoded['question']) : '';

                    if ($question !== '') {
                        $text .= "\n\n".__('Question:').' '.$question;
                    }

                    return $text;
                }
            }
        }

        return $rest !== '' ? $rest : null;
    }
}
