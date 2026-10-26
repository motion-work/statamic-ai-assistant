<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Support\BlueprintFieldValidator;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Illuminate\Support\Str;
use Statamic\Facades\Fieldset;

/**
 * Creates a reusable fieldset. On the sites this addon serves, fieldsets play
 * two roles: shared groups imported directly into blueprints (heros, SEO), and
 * page-builder components (often named component_*) that get registered as a
 * set of a container fieldset's components/replicator field — see
 * AddComponentSetTool for that second step.
 */
class CreateFieldsetTool extends AbstractAdvancedTool
{
    public function __construct(private BlueprintFieldValidator $validator = new BlueprintFieldValidator) {}

    public function name(): string
    {
        return 'create_fieldset';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_fieldset',
                'description' => 'Create a NEW reusable fieldset. Each field is {"handle": "...", "field": {"type": "...", "display": "...", ...config}} '
                    .'(an {"import": "<other_fieldset>"} row is also allowed). Applies IMMEDIATELY — only when the user explicitly asked. '
                    .'For a page-builder component (e.g. a component_* fieldset), ALSO register it afterwards with add_component_set so editors can use it in entries. '
                    .'For shared groups (heros, SEO), reference the new fieldset in blueprints via {"import": "<handle>"}.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle (e.g. "component_bookingslider"). Follow the site\'s existing naming (see list_fieldsets).',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Human title (defaults to the handle, titleized).',
                        ],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'Field definitions: [{"handle": "title", "field": {"type": "text", "display": "Title"}}, ...].',
                        ],
                    ],
                    'required' => ['handle', 'fields'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($err = $this->invalidHandleError($handle, 'fieldset')) {
            return ['ok' => false, 'error' => $err];
        }

        if (Fieldset::find($handle)) {
            return ['ok' => false, 'error' => "Fieldset \"{$handle}\" already exists."];
        }

        $fields = isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : [];
        $validation = $this->validator->validate($fields);
        if (! $validation['ok']) {
            return $validation;
        }

        $title = $this->stringArg($args, 'title');
        if ($title === '') {
            $title = Str::title(str_replace('_', ' ', $handle));
        }

        $context->reportActivity((string) __('Creating fieldset :handle', ['handle' => $handle]));

        $fieldset = Fieldset::make($handle)->setContents([
            'title' => $title,
            'fields' => $validation['fields'],
        ]);
        $fieldset->save();

        return [
            'ok' => true,
            'created' => true,
            'handle' => $handle,
            'title' => $title,
            'fields' => array_map(fn ($f) => $f['handle'] ?? 'import:'.$f['import'], $validation['fields']),
            'next_step' => 'If this fieldset is a page-builder component, register it now with add_component_set (find the container fieldset via list_fieldsets). If it is a shared group, import it in blueprints via {"import": "'.$handle.'"}.',
        ];
    }
}
