<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\BlueprintHintTargets;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Services\SetLayoutCatalog;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use Statamic\Facades\Collection;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Site-wide AI context for a service account setting up a site: the block
 * catalog it builds pages from, the site instructions and the style rules
 * that the AI agent's prompts and the connector's brief carry.
 */
final class SiteSetupTools
{
    public function __construct(
        private ConnectorContext $context,
        private SetHintsService $hints,
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private ContentCommitter $commits,
    ) {}

    /**
     * Every replicator/components block across the collection blueprints (or
     * one collection's, or one blueprint's), with its editor hint and where it
     * is used.
     *
     * @return array<string, mixed>
     */
    public function listBlocks(?string $collection, ?string $blueprint): array
    {
        $collection = trim((string) $collection);
        $blueprint = trim((string) $blueprint);
        if ($collection !== '' && Collection::findByHandle($collection) === null) {
            return ['error' => 'missing_collection', 'message' => 'No collection has the handle '.$collection.'.'];
        }

        $targets = new BlueprintHintTargets($this->hints);
        $catalog = new SetLayoutCatalog($this->hints);
        $blocks = [];

        $targets->eachTopLevelField(function (Field $field, array $where) use ($targets, $catalog, $collection, $blueprint, &$blocks) {
            [$inCollection, $inBlueprint] = explode('::', $where['key'], 2);
            if (($collection !== '' && $inCollection !== $collection) || ($blueprint !== '' && $inBlueprint !== $blueprint)) {
                return;
            }

            $targets->eachSetIn($field, function (Field $owner, string $handle, array $config, Fields $setFields) use ($catalog, $inCollection, $inBlueprint, &$blocks) {
                $blocks[$handle] ??= $this->block($handle, $config, $catalog->contentMix($setFields));
                $blocks[$handle]['used_in'][$inCollection.'::'.$inBlueprint.'::'.$owner->handle()] = [
                    'collection' => $inCollection,
                    'blueprint' => $inBlueprint,
                    'field' => $owner->handle(),
                ];
            });
        });

        ksort($blocks);
        $rows = array_map(fn (array $block) => [...$block, 'used_in' => array_values($block['used_in'])], array_values($blocks));

        return [
            'blocks' => $rows,
            'count' => count($rows),
            'without_hints' => array_values(array_map(fn (array $row) => $row['handle'], array_filter($rows, fn (array $row) => ! $row['has_hint']))),
        ];
    }

    /**
     * Replace the site instructions (Markdown) the AI agent and the
     * connector's brief follow; an empty string removes them.
     *
     * @return array<string, mixed>
     */
    public function writeSiteInstructions(string $markdown): array
    {
        if (! AgentAccess::allows('agent_settings', $this->context->actor())) {
            return ['saved' => false, 'error' => 'forbidden', 'message' => 'This account may not change the AI instructions. A super admin can grant "AI agent settings" under AI & Claude access. Nothing was saved.'];
        }

        $length = mb_strlen(trim($markdown));
        if ($length > SetHintsService::SITE_INSTRUCTIONS_MAX_CHARS) {
            return ['saved' => false, 'error' => 'too_long', 'message' => 'Site instructions may have at most '.SetHintsService::SITE_INSTRUCTIONS_MAX_CHARS.' characters; these have '.$length.'. Nothing was saved.'];
        }

        $previous = mb_strlen($this->hints->siteInstructions());
        $saved = $this->hints->saveSiteInstructions($markdown);
        $this->commits->commit('Update the AI site instructions', $this->context->actor());

        return [
            'saved' => true,
            'characters' => mb_strlen($saved),
            'previous_characters' => $previous,
            'prompt_characters' => SetHintsService::SITE_INSTRUCTIONS_PROMPT_CHARS,
            'truncated_in_prompts' => mb_strlen($saved) > SetHintsService::SITE_INSTRUCTIONS_PROMPT_CHARS,
        ];
    }

    /**
     * Replace the style rules. A list of strings applies to every site
     * language; an object maps a language (de, en, …) to its list. Languages
     * left out lose their rules, as with save_style_rules.
     *
     * @param  array<mixed>  $rules
     * @return array<string, mixed>
     */
    public function setStyleRules(array $rules): array
    {
        if (! GlossaryAccess::canEdit($this->context->actor())) {
            return ['saved' => false, 'error' => 'forbidden', 'message' => 'This account\'s role cannot change the style rules. A super admin can grant "Edit translation glossary and style rules" under Users, Roles. Nothing was saved.'];
        }

        $byLanguage = array_is_list($rules)
            ? array_fill_keys($this->glossary->languages(), $rules)
            : $rules;

        $saved = [];
        foreach ($this->styleRules->save($byLanguage) as $lang => $rule) {
            $saved[$lang] = $rule['instructions'];
        }
        $this->commits->commit('Update the style rules', $this->context->actor());

        return [
            'saved' => true,
            'rules' => (object) $saved,
            'synced_to_deepl' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function block(string $handle, array $config, string $contentMix): array
    {
        $hint = $this->hints->forSet($handle);

        return [
            'handle' => $handle,
            'title' => is_string($config['display'] ?? null) ? $config['display'] : Str::headline(str_replace('_', ' ', $handle)),
            'ai_description' => $hint['ai_description'] ?? '',
            'when_to_use' => array_values($hint['when_to_use'] ?? []),
            'has_hint' => $hint !== null,
            'content_mix' => $contentMix,
            'used_in' => [],
        ];
    }
}
