<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Lets the agent read a Statamic navigation as a hierarchy of page titles (and
 * URLs / linked entry ids). Useful to understand site structure, find the right
 * internal link target, or answer "what pages are under X?".
 *
 * Generic by design: it discovers navs at runtime via Nav::all(), reads the tree
 * for the best available site, and resolves each branch's title from the branch
 * label or its linked entry — so it keeps working when navs, pages, or sites
 * change. Nothing is hardcoded.
 */
class ReadNavTreeTool implements ChatTool
{
    private const DEFAULT_MAX_DEPTH = 6;

    private const MAX_NODES = 400;

    public function name(): string
    {
        return 'read_nav_tree';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_nav_tree',
                'description' => 'Read a site navigation as a hierarchy of page titles with their URLs and linked entry ids. '
                    .'Call with no arguments to list available navigations (and the tree if there is only one), or pass a '
                    .'"handle" to get that navigation\'s full tree. Use it to understand site structure or pick internal link targets.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'Optional navigation handle to read (e.g. "main", "footer"). Omit to list navigations.',
                        ],
                        'site' => [
                            'type' => 'string',
                            'description' => 'Optional site/locale handle. Defaults to the site that has a populated tree.',
                        ],
                        'max_depth' => [
                            'type' => 'integer',
                            'description' => 'Optional maximum nesting depth to return (default 6).',
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
        $maxDepth = isset($args['max_depth']) && is_numeric($args['max_depth'])
            ? max(1, min(12, (int) $args['max_depth']))
            : self::DEFAULT_MAX_DEPTH;

        $navs = Nav::all();

        if ($navs->isEmpty()) {
            return ['ok' => true, 'navigations' => [], 'note' => 'No navigations are defined.'];
        }

        if ($wantHandle === '') {
            $list = $navs->map(fn ($n) => ['handle' => (string) $n->handle(), 'title' => (string) $n->title()])
                ->values()->all();

            // Convenience: with a single nav, return its tree directly.
            if (count($list) === 1) {
                return $this->readOne($navs->first(), $site, $maxDepth, $context);
            }

            $context->reportActivity((string) __('Listing navigations'));

            return ['ok' => true, 'navigations' => $list, 'note' => 'Call again with a "handle" to read a navigation tree.'];
        }

        $nav = Nav::findByHandle($wantHandle);
        if (! $nav) {
            return [
                'ok' => false,
                'error' => 'navigation_not_found',
                'handle' => $wantHandle,
                'available' => $navs->map(fn ($n) => $n->handle())->values()->all(),
            ];
        }

        return $this->readOne($nav, $site, $maxDepth, $context);
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }

    /**
     * @return array<string, mixed>
     */
    private function readOne($nav, string $site, int $maxDepth, ToolContext $context): array
    {
        $context->reportActivity((string) __('Reading navigation ":handle"', ['handle' => (string) $nav->handle()]));

        $resolved = $this->resolveTree($nav, $site);

        $nodeBudget = self::MAX_NODES;
        $tree = $this->serializeBranches($resolved['tree'], $maxDepth, 1, $nodeBudget);

        return [
            'ok' => true,
            'handle' => (string) $nav->handle(),
            'title' => (string) $nav->title(),
            'site' => $resolved['site'],
            'tree' => $tree,
            'truncated' => $nodeBudget <= 0,
        ];
    }

    /**
     * Pick the site whose tree is populated: requested → default → first non-empty.
     *
     * @return array{site: string, tree: array<int, mixed>}
     */
    private function resolveTree($nav, string $site): array
    {
        $candidates = array_values(array_unique(array_filter([
            $site,
            Site::default()->handle(),
            ...Site::all()->map(fn ($s) => $s->handle())->all(),
        ])));

        $firstNonEmpty = null;
        foreach ($candidates as $handle) {
            $navTree = $nav->in($handle);
            if (! $navTree) {
                continue;
            }
            $tree = $navTree->tree();
            if (is_array($tree) && $tree !== []) {
                if ($firstNonEmpty === null) {
                    $firstNonEmpty = ['site' => $handle, 'tree' => $tree];
                }
                if ($handle === ($site !== '' ? $site : $handle)) {
                    return ['site' => $handle, 'tree' => $tree];
                }
            }
        }

        return $firstNonEmpty ?? ['site' => $site !== '' ? $site : Site::default()->handle(), 'tree' => []];
    }

    /**
     * @param  array<int, mixed>  $branches
     * @return array<int, array<string, mixed>>
     */
    private function serializeBranches(array $branches, int $maxDepth, int $depth, int &$budget): array
    {
        $out = [];

        foreach ($branches as $branch) {
            if ($budget <= 0) {
                break;
            }
            if (! is_array($branch)) {
                continue;
            }

            $budget--;

            $entryId = ! empty($branch['entry']) && is_string($branch['entry']) ? $branch['entry'] : null;
            $entry = $entryId ? Entry::find($entryId) : null;

            $title = '';
            if (isset($branch['title']) && is_string($branch['title']) && trim($branch['title']) !== '') {
                $title = trim($branch['title']);
            } elseif ($entry) {
                $title = (string) ($entry->value('title') ?? '');
            }

            $url = null;
            if (! empty($branch['url']) && is_string($branch['url'])) {
                $url = $branch['url'];
            } elseif ($entry && method_exists($entry, 'url')) {
                $url = $entry->url();
            }

            $node = array_filter([
                'title' => $title !== '' ? $title : ($url ?: '(untitled)'),
                'url' => $url,
                'entry_id' => $entryId,
            ], fn ($v) => $v !== null);

            if (! empty($branch['children']) && is_array($branch['children']) && $depth < $maxDepth) {
                $children = $this->serializeBranches($branch['children'], $maxDepth, $depth + 1, $budget);
                if ($children !== []) {
                    $node['children'] = $children;
                }
            }

            $out[] = $node;
        }

        return $out;
    }
}
