<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Best-effort, dependency-free repair of the malformed JSON mid-tier models
 * commonly emit for large, deeply nested objects (Bard/replicator content full
 * of quoted HTML). It fixes only frequent, SAFE-to-fix defects and never
 * guarantees valid output — callers MUST re-validate the result with
 * json_decode and fall back (e.g. to an LLM correction round) when it still
 * fails to parse. A repair that yields invalid JSON therefore costs nothing.
 *
 * Fixed in one string-aware pass:
 *   - stray unescaped double-quotes inside string values, e.g. the model wrote
 *     `"auch \"Jumbo" genannt"` — opening quote escaped, closing one not — which
 *     otherwise terminates the string early;
 *   - trailing commas before `}` or `]`;
 *   - an unterminated string and/or unclosed brackets at the end of a truncated
 *     response.
 *
 * It deliberately does NOT try to fix structural mistakes (e.g. a bare object
 * where an object member is expected) — those are ambiguous, so they safely
 * fall through to the caller's fallback.
 */
final class JsonRepair
{
    private string $out = '';

    private bool $inString = false;

    /** @var array<int, string> open '{' / '[' awaiting their close */
    private array $stack = [];

    private int $i = 0;

    private int $len;

    private function __construct(private string $json)
    {
        $this->len = strlen($json);
    }

    public static function repair(string $json): string
    {
        return (new self($json))->run();
    }

    private function run(): string
    {
        while ($this->i < $this->len) {
            $this->inString ? $this->stringChar() : $this->structureChar();
        }

        // Truncated tail: close a dangling string, then any still-open brackets
        // in reverse order.
        if ($this->inString) {
            $this->out .= '"';
        }
        while ($this->stack !== []) {
            $this->out .= array_pop($this->stack) === '{' ? '}' : ']';
        }

        return $this->out;
    }

    /**
     * Consumes the next character(s) inside a string value.
     */
    private function stringChar(): void
    {
        $c = $this->json[$this->i];

        if ($c === '\\') {
            // Preserve an escape pair verbatim (guard a trailing lone backslash).
            $this->out .= $c;
            if ($this->i + 1 < $this->len) {
                $this->out .= $this->json[$this->i + 1];
                $this->i += 2;
            } else {
                $this->i++;
            }

            return;
        }

        if ($c === '"') {
            // A quote closes the string only when the next meaningful char
            // continues the structure (`:` `,` `}` `]`) or input ends.
            // Otherwise it is a literal quote the model forgot to escape.
            if (self::isStructuralNext($this->json, $this->i + 1)) {
                $this->inString = false;
                $this->out .= '"';
            } else {
                $this->out .= '\\"';
            }
            $this->i++;

            return;
        }

        $this->out .= $c;
        $this->i++;
    }

    /**
     * Consumes the next character outside a string.
     */
    private function structureChar(): void
    {
        $c = $this->json[$this->i];

        if ($c === '"') {
            $this->inString = true;
            $this->out .= $c;
            $this->i++;

            return;
        }

        if ($c === '{' || $c === '[') {
            $this->stack[] = $c;
            $this->out .= $c;
            $this->i++;

            return;
        }

        if ($c === '}' || $c === ']') {
            array_pop($this->stack);
            $this->out .= $c;
            $this->i++;

            return;
        }

        if ($c === ',') {
            // Drop a trailing comma: one followed (after whitespace) by `}`/`]`.
            $j = $this->i + 1;
            while ($j < $this->len && ctype_space($this->json[$j])) {
                $j++;
            }
            if ($j < $this->len && ($this->json[$j] === '}' || $this->json[$j] === ']')) {
                $this->i++;

                return;
            }

            $this->out .= $c;
            $this->i++;

            return;
        }

        $this->out .= $c;
        $this->i++;
    }

    /**
     * Whether the next non-whitespace char from $from is a JSON structural token
     * that legitimately follows a closing string quote (or the input ends).
     */
    private static function isStructuralNext(string $s, int $from): bool
    {
        $len = strlen($s);
        $j = $from;
        while ($j < $len && ctype_space($s[$j])) {
            $j++;
        }

        return $j >= $len || in_array($s[$j], [':', ',', '}', ']'], true);
    }
}
