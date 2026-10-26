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
    public static function repair(string $json): string
    {
        $len = strlen($json);
        $out = '';
        $inString = false;
        $stack = [];   // open '{' / '[' awaiting their close
        $i = 0;

        while ($i < $len) {
            $c = $json[$i];

            if ($inString) {
                if ($c === '\\') {
                    // Preserve an escape pair verbatim (guard a trailing lone backslash).
                    $out .= $c;
                    if ($i + 1 < $len) {
                        $out .= $json[$i + 1];
                        $i += 2;
                    } else {
                        $i++;
                    }

                    continue;
                }

                if ($c === '"') {
                    // A quote closes the string only when the next meaningful char
                    // continues the structure (`:` `,` `}` `]`) or input ends.
                    // Otherwise it is a literal quote the model forgot to escape.
                    if (self::isStructuralNext($json, $i + 1)) {
                        $inString = false;
                        $out .= '"';
                    } else {
                        $out .= '\\"';
                    }
                    $i++;

                    continue;
                }

                $out .= $c;
                $i++;

                continue;
            }

            // Outside a string.
            if ($c === '"') {
                $inString = true;
                $out .= $c;
                $i++;

                continue;
            }

            if ($c === '{' || $c === '[') {
                $stack[] = $c;
                $out .= $c;
                $i++;

                continue;
            }

            if ($c === '}' || $c === ']') {
                array_pop($stack);
                $out .= $c;
                $i++;

                continue;
            }

            if ($c === ',') {
                // Drop a trailing comma: one followed (after whitespace) by `}`/`]`.
                $j = $i + 1;
                while ($j < $len && ctype_space($json[$j])) {
                    $j++;
                }
                if ($j < $len && ($json[$j] === '}' || $json[$j] === ']')) {
                    $i++;

                    continue;
                }

                $out .= $c;
                $i++;

                continue;
            }

            $out .= $c;
            $i++;
        }

        // Truncated tail: close a dangling string, then any still-open brackets
        // in reverse order.
        if ($inString) {
            $out .= '"';
        }
        while ($stack !== []) {
            $out .= array_pop($stack) === '{' ? '}' : ']';
        }

        return $out;
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
