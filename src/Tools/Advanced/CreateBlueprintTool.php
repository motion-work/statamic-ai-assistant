<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Support\BlueprintFieldValidator;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;

/**
 * Creates a new entry/term blueprint from validated field definitions. The
 * create is guarded by a file lock (adapted from cboxdk/statamic-mcp) because
 * planner tool calls run in queue workers that can race on the same handle.
 */
class CreateBlueprintTool extends AbstractAdvancedTool
{
    use ResolvesBlueprintNamespace;

    public function __construct(private BlueprintFieldValidator $validator = new BlueprintFieldValidator) {}

    public function name(): string
    {
        return 'create_blueprint';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_blueprint',
                'description' => 'Create a NEW blueprint for a collection (entry blueprint) or taxonomy (term blueprint). '
                    .'PREFER the "tabs" parameter and mirror the tab layout of an existing similar blueprint (read_blueprint first): '
                    .'typically a "main" tab with the title + content sections, an "seo" tab importing the site\'s seo fieldset, and a "sidebar" tab with slug/date/settings. '
                    .'Only use the flat "fields" parameter when the site\'s existing blueprints are untabbed. '
                    .'Each field is {"handle": "...", "field": {"type": "...", "display": "...", ...config}}. '
                    .'To reuse an EXISTING fieldset (hero, seo, ...) add an import row {"import": "<fieldset_handle>"} (optional "prefix": "x_") instead of redefining its fields — check list_fieldsets first. '
                    .'A single fieldset field can be referenced as {"handle": "...", "field": "<fieldset>.<field>"}. '
                    .'Include a title text field first unless the user asked otherwise. '
                    .'The collection or taxonomy must already exist. Changes apply IMMEDIATELY — only do this when the user explicitly asked for it.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle for the new blueprint (e.g. "event").',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Human title (defaults to the handle, titleized).',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle this entry blueprint belongs to.',
                        ],
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Taxonomy handle this term blueprint belongs to.',
                        ],
                        'tabs' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'PREFERRED tabbed layout, matching the site\'s conventions: '
                                .'[{"handle": "main", "display": "Main", "sections": [{"fields": [{"handle": "title", "field": {"type": "text"}}, {"import": "hero"}]}, {"display": "Content", "fields": [{"import": "main_components"}]}]}, '
                                .'{"handle": "seo", "display": "SEO", "sections": [{"fields": [{"import": "seo"}]}]}, '
                                .'{"handle": "sidebar", "sections": [{"fields": [{"handle": "slug", "field": {"type": "slug"}}]}]}]. '
                                .'Provide either "tabs" or "fields", not both.',
                        ],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'Flat single-tab fallback: [{"handle": "title", "field": {"type": "text", "display": "Title"}}, ...]. Use "tabs" instead whenever the site\'s blueprints are tabbed.',
                        ],
                    ],
                    'required' => ['handle'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($err = $this->invalidHandleError($handle, 'blueprint')) {
            return ['ok' => false, 'error' => $err];
        }

        $ns = $this->resolveBlueprintNamespace($args);
        if (! $ns['ok']) {
            return $ns;
        }

        $tabs = isset($args['tabs']) && is_array($args['tabs']) ? $args['tabs'] : [];
        $fields = isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : [];

        if ($tabs !== [] && $fields !== []) {
            return ['ok' => false, 'error' => 'Provide either "tabs" or "fields", not both — put the field rows inside the tab sections.'];
        }

        if ($tabs !== []) {
            $validation = $this->validator->validateTabs($tabs);
            if (! $validation['ok']) {
                return $validation;
            }
            $fieldContents = ['tabs' => $validation['tabs']];
            $fieldRows = collect($validation['tabs'])
                ->flatMap(fn (array $tab) => collect($tab['sections'])->flatMap(fn (array $s) => $s['fields']))
                ->all();
        } else {
            $validation = $this->validator->validate($fields);
            if (! $validation['ok']) {
                return $validation;
            }
            $fieldContents = ['fields' => $validation['fields']];
            $fieldRows = $validation['fields'];
        }

        $title = $this->stringArg($args, 'title');
        if ($title === '') {
            $title = Str::title(str_replace('_', ' ', $handle));
        }

        // File lock: queue workers can race on creating the same handle.
        $lockPath = storage_path('framework/cache/blueprint-'.md5($ns['namespace'].'.'.$handle).'.lock');
        $lockDir = dirname($lockPath);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $lock = fopen($lockPath, 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }

            return ['ok' => false, 'error' => 'Could not acquire lock for blueprint creation. Try again.'];
        }

        try {
            if ($this->findBlueprintIn($ns['namespace'], $handle)) {
                return ['ok' => false, 'error' => "Blueprint \"{$handle}\" already exists in {$ns['owner_type']} \"{$ns['owner']}\". Use update_blueprint to change it."];
            }

            $context->reportActivity((string) __('Creating blueprint :handle', ['handle' => $handle]));

            $blueprint = Blueprint::make($handle)
                ->setNamespace($ns['namespace'])
                ->setContents(array_merge(['title' => $title], $fieldContents));
            $blueprint->save();

            $result = [
                'ok' => true,
                'created' => true,
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                $ns['owner_type'] => $ns['owner'],
                'fields' => array_map(fn ($f) => $f['handle'] ?? 'import:'.$f['import'], $fieldRows),
            ];
            if (isset($fieldContents['tabs'])) {
                $result['tabs'] = array_keys($fieldContents['tabs']);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }
}
