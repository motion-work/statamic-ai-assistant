<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use Statamic\Facades\Site;

/**
 * Reading and changing the translation glossary and style rules.
 */
final class GlossaryTools
{
    public function __construct(
        private ConnectorContext $context,
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private ContentCommitter $commits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readGlossary(): array
    {
        return [
            'languages' => $this->glossary->languages(),
            'entries' => $this->glossary->entries(),
            'synced_to_deepl' => false,
            'note' => 'Stored for the translation team and the Control Panel. This connection does not push the table to DeepL.',
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>
     */
    public function saveGlossary(array $entries, bool $confirmed): array
    {
        if (($denied = $this->refuseGlossary()) !== null) {
            return $denied;
        }

        if (! $confirmed) {
            return [
                'saved' => false,
                'message' => 'Nothing was saved. This replaces the whole glossary. Show the next table to the user, then call again with confirmed true.',
                'current' => $this->glossary->entries(),
                'next' => $entries,
            ];
        }

        $saved = $this->glossary->save($entries);
        $this->commits->commit('Update the translation glossary', $this->context->actor());

        return [
            'saved' => true,
            'synced_to_deepl' => false,
            'entries' => $saved,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readStyleRules(): array
    {
        $rules = [];
        foreach ($this->styleRules->rules() as $lang => $rule) {
            $rules[$lang] = $rule['instructions'];
        }

        return [
            'rules' => $rules,
            'synced_to_deepl' => false,
            'note' => 'Follow these instructions while translating. This connection does not push them to DeepL.',
        ];
    }

    /**
     * @param  array<string, mixed>  $instructionsByLang
     * @return array<string, mixed>
     */
    public function saveStyleRules(array $instructionsByLang, bool $confirmed): array
    {
        if (($denied = $this->refuseGlossary()) !== null) {
            return $denied;
        }

        if (! $confirmed) {
            return [
                'saved' => false,
                'message' => 'Nothing was saved. Pass the complete rule set; omitted languages are removed. Show it to the user, then call again with confirmed true.',
                'current' => $this->readStyleRules()['rules'],
                'next' => $instructionsByLang,
            ];
        }

        $saved = $this->styleRules->save($instructionsByLang);
        $this->commits->commit('Update the translation style rules', $this->context->actor());
        $rules = [];
        foreach ($saved as $lang => $rule) {
            $rules[$lang] = $rule['instructions'];
        }

        return [
            'saved' => true,
            'synced_to_deepl' => false,
            'rules' => $rules,
        ];
    }

    /**
     * The glossary and style rules apply to every translation on the site, so
     * changing them needs the role permission, as on the Control Panel page.
     *
     * @return array<string, mixed>|null
     */
    private function refuseGlossary(): ?array
    {
        if ($this->context->actor() === null || GlossaryAccess::canEdit($this->context->actor())) {
            return null;
        }

        return [
            'saved' => false,
            'error' => 'forbidden',
            'message' => 'Your Control Panel role cannot change the glossary and style rules. A super admin can grant "Edit translation glossary and style rules" under Users, Roles. Nothing was saved.',
        ];
    }
}
