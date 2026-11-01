<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\FieldtypeRepository;

/**
 * An inline field config: near-miss keys get a correction hint, the fieldtype
 * and referenced taxonomies/collections must exist, and Antlers expressions
 * are stripped from string values.
 */
final class FieldConfigRules
{
    /** Antlers tags and unescaped echoes. */
    public const ANTLERS = '/\{\{.*?\}\}|\{!!.*?!!\}/s';

    /** Common near-miss config keys LLMs send incorrectly. */
    private const PARAM_CORRECTIONS = [
        'taxonomy' => 'taxonomies',
        'collection' => 'collections',
        'field_type' => 'type',
        'fieldtype' => 'type',
        'name' => 'handle',
    ];

    /**
     * Why an inline field config is invalid, or null.
     *
     * @param  array<string, mixed>  $config
     * @return array{ok: false, error: string}|null
     */
    public function configError(string $handle, array $config): ?array
    {
        $fieldtypes = app(FieldtypeRepository::class);

        foreach (self::PARAM_CORRECTIONS as $wrong => $correct) {
            if (isset($config[$wrong]) && ! isset($config[$correct])) {
                return ['ok' => false, 'error' => "Field \"{$handle}\" has \"{$wrong}\" in its config. Did you mean \"{$correct}\"? Rename \"{$wrong}\" to \"{$correct}\"."];
            }
        }

        $type = $config['type'] ?? null;
        if (! is_string($type) || trim($type) === '') {
            return ['ok' => false, 'error' => "Field \"{$handle}\" is missing \"type\" in its field config. Example: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\"}}"];
        }

        try {
            $fieldtypes->find($type);
        } catch (FieldtypeNotFoundException) {
            $available = $fieldtypes->handles()->values()->sort()->implode(', ');

            return ['ok' => false, 'error' => "Unknown field type \"{$type}\" for field \"{$handle}\". Available types: {$available}"];
        }

        if (isset($config['taxonomies']) && is_array($config['taxonomies'])) {
            foreach ($config['taxonomies'] as $taxHandle) {
                if (is_string($taxHandle) && ! Taxonomy::find($taxHandle)) {
                    return ['ok' => false, 'error' => "Field \"{$handle}\" references taxonomy \"{$taxHandle}\" which does not exist. Create it first with create_taxonomy."];
                }
            }
        }

        if (isset($config['collections']) && is_array($config['collections'])) {
            foreach ($config['collections'] as $colHandle) {
                if (is_string($colHandle) && ! Collection::find($colHandle)) {
                    return ['ok' => false, 'error' => "Field \"{$handle}\" references collection \"{$colHandle}\" which does not exist. Create it first with create_collection."];
                }
            }
        }

        return null;
    }

    /**
     * String config values with Antlers template expressions stripped.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function withoutAntlers(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                $config[$key] = strip_tags((string) preg_replace(self::ANTLERS, '', $value));
            }
        }

        return $config;
    }
}
