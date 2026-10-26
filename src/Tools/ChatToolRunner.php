<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Registry + dispatcher for ChatTools, shared by the entry-generator loop and the
 * agentic planner. It owns the boilerplate both loops used to duplicate: turning
 * an assistant's tool_calls into `role: tool` reply messages, enforcing per-tool
 * call budgets, JSON-encoding results, and firing the stream heartbeat.
 *
 * Tools that don't fit the stateless ChatTool contract (e.g. the planner's
 * stateful create/update job tools) are handled via the $fallback passed to
 * consume() — keeping that concern out of this generic runner.
 */
class ChatToolRunner
{
    /**
     * Hard cap on a single tool result's JSON size. Oversized results are
     * replaced with an actionable error instead of being sent to the model,
     * where they would blow the context/token budget of a whole run.
     */
    private const MAX_RESULT_BYTES = 100_000;

    /** @var array<string, ChatTool> */
    private array $tools = [];

    /** @var array<string, int> per-tool call counts this run */
    private array $counts = [];

    /**
     * @param  array<int, ChatTool>  $tools
     * @param  (callable(string $name, array<string, mixed> $result): void)|null  $onResult
     *         Observer invoked with every dispatched tool's name + result array
     *         (registered tools only, after budget/exception handling). Lets a
     *         loop react to outcomes — e.g. the planner counting successful
     *         structural writes — without parsing the JSON tool replies.
     */
    public function __construct(array $tools, private ToolContext $context, private $onResult = null)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * "Microcompaction": replace old, large tool results in the working
     * conversation with a small stub. Every stale tool reply is re-sent (and
     * paid for) on every subsequent completion, and by late rounds it drowns
     * small models in data they already acted on. The newest $keepLast tool
     * messages stay intact; earlier ones larger than $maxBytes are elided —
     * the model can re-call the tool if it genuinely still needs the data.
     *
     * @param  array<int, array<string, mixed>>  $working  Conversation being built (mutated)
     */
    public static function compactToolMessages(array &$working, int $keepLast = 6, int $maxBytes = 2048): void
    {
        $toolIndexes = [];
        foreach ($working as $i => $msg) {
            if (is_array($msg) && ($msg['role'] ?? '') === 'tool') {
                $toolIndexes[] = $i;
            }
        }

        $eligible = array_slice($toolIndexes, 0, max(0, count($toolIndexes) - $keepLast));

        foreach ($eligible as $i) {
            $content = $working[$i]['content'] ?? '';

            if (! is_string($content) || strlen($content) <= $maxBytes || str_contains($content, '"elided":true')) {
                continue;
            }

            $working[$i]['content'] = json_encode([
                'elided' => true,
                'note' => 'Old tool result removed to save context. Call the tool again if you still need this data.',
            ]);
        }
    }

    /**
     * Tool definitions payload for the chat completion request.
     *
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (ChatTool $t) => $t->definition(), $this->tools));
    }

    /** How many times a given tool has been dispatched this run. */
    public function callCount(string $name): int
    {
        return $this->counts[$name] ?? 0;
    }

    /** Total dispatches across all registered tools this run. */
    public function totalCalls(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Append `role: tool` replies for every call in an assistant turn. Registered
     * tools are dispatched here; unknown names fall to $fallback (which returns a
     * result array, or null to yield a standard "unknown tool" reply).
     *
     * @param  array<int, mixed>  $toolCalls
     * @param  array<int, array<string, mixed>>  $working  Conversation being built (mutated)
     * @param  (callable(string $name, string $argumentsJson): (array<string, mixed>|null))|null  $fallback
     */
    public function consume(array $toolCalls, array &$working, ?callable $fallback = null): void
    {
        foreach ($toolCalls as $tc) {
            if (! is_array($tc)) {
                continue;
            }

            $id = isset($tc['id']) && is_string($tc['id']) ? $tc['id'] : '';
            if (($tc['type'] ?? '') !== 'function' || $id === '') {
                continue;
            }

            $fn = $tc['function'] ?? [];
            $name = isset($fn['name']) && is_string($fn['name']) ? $fn['name'] : '';
            $args = isset($fn['arguments']) && is_string($fn['arguments']) ? $fn['arguments'] : '{}';

            $result = $this->dispatch($name, $args, $fallback);

            $content = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($content !== false && strlen($content) > self::MAX_RESULT_BYTES) {
                Log::warning('[chat-tool] result exceeded size cap, replaced with error', [
                    'tool' => $name,
                    'bytes' => strlen($content),
                ]);

                $content = json_encode([
                    'ok' => false,
                    'error' => 'result_too_large',
                    'hint' => 'The result was '.round(strlen($content) / 1024).'KB. Repeat the call with narrower arguments (a smaller limit, a more specific query, or fewer fields).',
                ], JSON_UNESCAPED_UNICODE);
            }

            $working[] = [
                'role' => 'tool',
                'tool_call_id' => $id,
                'content' => $content,
            ];

            $this->context->heartbeat();
        }
    }

    /**
     * @param  (callable(string, string): (array<string, mixed>|null))|null  $fallback
     * @return array<string, mixed>
     */
    private function dispatch(string $name, string $argumentsJson, ?callable $fallback): array
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            if ($fallback !== null) {
                $result = $fallback($name, $argumentsJson);
                if (is_array($result)) {
                    return $result;
                }
            }

            Log::warning('[chat-tool] unknown tool requested', ['name' => $name]);

            return ['ok' => false, 'error' => 'unknown tool: '.$name];
        }

        $used = $this->counts[$name] ?? 0;
        $max = $tool->maxCalls();
        if ($max !== null && $used >= $max) {
            $this->context->addWarning((string) __(':tool: maximum number of calls for this request was reached.', ['tool' => $name]));

            return ['ok' => false, 'error' => $name.'_limit_reached'];
        }
        // Count every attempt (even for uncapped tools, so callCount() is reliable,
        // and so a repeatedly failing call can't spin the loop against the round cap).
        $this->counts[$name] = $used + 1;

        try {
            $result = $tool->handle($argumentsJson, $this->context);
        } catch (\Throwable $e) {
            Log::warning('[chat-tool] handler threw', ['tool' => $name, 'error' => $e->getMessage()]);

            $result = ['ok' => false, 'error' => 'tool_error'];
        }

        if ($this->onResult !== null) {
            ($this->onResult)($name, $result);
        }

        return $result;
    }
}
