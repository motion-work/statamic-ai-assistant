<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Str;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

/**
 * Lets the agent read Statamic global sets (e.g. general, contact, CTA) so it can
 * reuse site-wide values — phone numbers, addresses, booking links, default CTAs —
 * when writing or answering about entries.
 *
 * Generic by design: it discovers every global set at runtime via GlobalSet::all()
 * and reads their raw values, so it keeps working when sets are renamed, added, or
 * their fields change. No handle is hardcoded.
 */
class ReadGlobalsTool implements ChatTool
{
    public function name(): string
    {
        return 'read_globals';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_globals',
                'description' => 'Read Statamic global sets (site-wide values such as general settings, contact details, '
                    .'social links, and default call-to-action links). Call with no arguments to get every global set and its '
                    .'values, or pass a specific "handle" to read just one set. Use this to reuse real contact info or CTA '
                    .'links instead of inventing them.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'Optional handle of a single global set to read (e.g. "contact"). Omit to read all sets.',
                        ],
                        'site' => [
                            'type' => 'string',
                            'description' => 'Optional site/locale handle to read values from. Defaults to the current site.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        $args = is_array($args) ? $args : [];
        $wantHandle = isset($args['handle']) && is_string($args['handle']) ? trim($args['handle']) : '';
        $site = isset($args['site']) && is_string($args['site']) ? trim($args['site']) : '';

        $sets = GlobalSet::all();
        if ($wantHandle !== '') {
            $sets = $sets->filter(fn ($s) => $s->handle() === $wantHandle)->values();

            if ($sets->isEmpty()) {
                return [
                    'ok' => false,
                    'error' => 'global_set_not_found',
                    'handle' => $wantHandle,
                    'available' => GlobalSet::all()->map(fn ($s) => $s->handle())->values()->all(),
                ];
            }
        }

        $context->reportActivity($wantHandle !== ''
            ? (string) __('Reading global set ":handle"', ['handle' => $wantHandle])
            : (string) __('Reading site globals'));

        $out = [];
        foreach ($sets as $set) {
            $variables = $this->localizedVariables($set, $site);

            $out[] = [
                'handle' => (string) $set->handle(),
                'title' => (string) $set->title(),
                'values' => $variables ? $this->summarize($variables->data()->all()) : [],
            ];
        }

        return [
            'ok' => true,
            'site' => $site !== '' ? $site : Site::current()->handle(),
            'globals' => $out,
        ];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }

    /**
     * Best-effort localized variables for a set: requested site, then current,
     * then default, then any localization that exists — so a set is never blank
     * just because it has no row for one site.
     */
    private function localizedVariables($set, string $site)
    {
        foreach ([$site, Site::current()->handle(), Site::default()->handle()] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $vars = $set->in($candidate);
            if ($vars) {
                return $vars;
            }
        }

        return $set->localizations()->first() ?: null;
    }

    /**
     * Coerce arbitrary stored values into a bounded, JSON-safe structure so a huge
     * Bard field or asset list can't blow the model's context window.
     */
    private function summarize(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return Str::limit($value, 600);
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            if ($depth >= 4) {
                return '[…]';
            }

            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 50) {
                    $out['…'] = 'truncated';
                    break;
                }
                $out[$key] = $this->summarize($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? Str::limit((string) $value, 600)
                : '['.get_class($value).']';
        }

        return null;
    }
}
