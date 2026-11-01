<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\CollectionSites;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * Reading an entry for translation and saving the localization Claude wrote:
 * preview (segments, glossary hits, style rules) and confirmed save.
 */
final class LocalizationTools
{
    public function __construct(
        private ConnectorContext $context,
        private EntryTranslator $translator,
        private SiteLanguageResolver $sites,
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private ContentCommitter $commits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readEntry(string $entryId): array
    {
        $entry = $this->context->findEntry($entryId);
        if (isset($entry['error'])) {
            return $entry;
        }
        if (($denied = $this->context->refuse('view', $entry)) !== null) {
            return $denied;
        }

        return array_merge($this->context->entrySummary($entry), [
            'segments' => $this->translator->externalSegments($entry),
            'glossary' => $this->glossaryPayload(),
            'style_rules' => $this->stylePayload(),
            'translator' => 'The connected model translates these segments with its own account. DeepL and Infomaniak are not used.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewLocalization(string $entryId, string $target): array
    {
        $resolved = $this->resolvePair($entryId, $target);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        /** @var Entry $source */
        $source = $resolved['source'];
        $root = $resolved['root'];
        $site = $resolved['site'];
        $existing = $resolved['existing'];
        if (($denied = $this->refuseLocalization($source, $root, $site->handle())) !== null) {
            return $denied;
        }

        $segments = $this->translator->externalSegments($source);
        $token = (string) Str::uuid();

        Cache::put($this->tokenKey($token), [
            'source_id' => (string) $source->id(),
            'root_id' => (string) $root->id(),
            'target_site' => $site->handle(),
            'segment_ids' => array_column($segments, 'id'),
            'fingerprint' => $this->context->fingerprint($source, $segments),
            'user_id' => $this->context->actor() !== null ? (string) $this->context->actor()->id() : null,
        ], now()->addMinutes(ConnectorContext::TOKEN_TTL_MINUTES));

        $action = $existing ? 'update' : 'create';
        $published = (bool) $root->published();
        $targetName = $site->name();

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => ConnectorContext::TOKEN_TTL_MINUTES,
            'action' => $action,
            'source' => $this->context->entrySummary($source),
            'root_entry_id' => (string) $root->id(),
            'target' => [
                'handle' => $site->handle(),
                'locale' => $site->locale(),
                'name' => $targetName,
            ],
            'existing_entry_id' => $existing ? (string) $existing->id() : null,
            'published' => $published,
            'segments' => $segments,
            'glossary' => $this->glossaryPayload(),
            'style_rules' => $this->stylePayload(),
            'message' => $this->previewMessage($action, $source, $root, $targetName, $existing, $published, count($segments)),
        ];
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, mixed>
     */
    public function saveLocalization(string $token, array $translations, bool $confirmed): array
    {
        if (! $confirmed) {
            return [
                'saved' => false,
                'error' => 'not_confirmed',
                'message' => 'Nothing was saved. Show the preview to the user and call save_localization again with confirmed true only after they agree.',
            ];
        }

        $plan = Cache::get($this->tokenKey($token));
        if (! is_array($plan) || ($plan['user_id'] ?? null) !== ($this->context->actor() !== null ? (string) $this->context->actor()->id() : null)) {
            return [
                'saved' => false,
                'error' => 'preview_required',
                'message' => 'Call preview_localization first and reuse its confirmation_token. Nothing was saved.',
            ];
        }

        $source = EntryFacade::find($plan['source_id']);
        $root = EntryFacade::find($plan['root_id']);
        if (! $source || ! $root) {
            return ['saved' => false, 'error' => 'missing_entry', 'message' => 'The entry from the preview no longer exists. Nothing was saved.'];
        }
        if (($denied = $this->refuseLocalization($source, $root, $plan['target_site'])) !== null) {
            return $denied;
        }
        if ($this->context->fingerprint($source, $this->translator->externalSegments($source)) !== $plan['fingerprint']) {
            Cache::forget($this->tokenKey($token));

            return [
                'saved' => false,
                'error' => 'entry_changed',
                'message' => 'Someone changed the source entry after the preview. Read it again and preview the translation anew. Nothing was saved.',
            ];
        }

        $strings = [];
        foreach ($translations as $id => $text) {
            if (is_string($text)) {
                $strings[(string) $id] = $text;
            }
        }

        $expected = array_map('strval', (array) $plan['segment_ids']);
        $missing = array_values(array_diff($expected, array_keys($strings)));
        if ($missing !== []) {
            return [
                'saved' => false,
                'error' => 'missing_segments',
                'missing' => $missing,
                'message' => 'Every segment from the preview needs a translation. Nothing was saved.',
            ];
        }

        $existing = $root->existsIn($plan['target_site']) ? $root->in($plan['target_site']) : null;

        try {
            $saved = $this->translator->saveExternalTranslation($source, $plan['target_site'], $strings, $existing, $root);
        } catch (\InvalidArgumentException $e) {
            return ['saved' => false, 'error' => 'invalid_translation', 'message' => $e->getMessage()];
        }

        Cache::forget($this->tokenKey($token));
        $this->commits->commit('Translate "'.EntryLabel::for($saved).'" into '.$plan['target_site'], $this->context->actor());

        return [
            'saved' => true,
            'translated_by' => 'the connected model',
            'site_api_used' => false,
            'entry_id' => (string) $saved->id(),
            'origin_entry_id' => (string) $root->id(),
            'site' => (string) $saved->locale(),
            'title' => EntryLabel::for($saved),
            'edit_url' => $saved->editUrl(),
            'published' => (bool) $saved->published(),
            'unresolved_references' => $this->translator->takeUnresolvedReferences(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePair(string $entryId, string $target): array
    {
        $source = $this->context->findEntry($entryId);
        if (is_array($source)) {
            return $source;
        }

        $resolved = $this->sites->resolve($target);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $site = $resolved['site'];
        $root = $source;
        while ($root->hasOrigin() && $root->origin()) {
            $root = $root->origin();
        }

        if ($site->handle() === (string) $root->locale()) {
            return [
                'error' => 'same_site',
                'message' => 'The target site is the source entry\'s own site ('.$site->handle().'). Pick another site.',
            ];
        }

        if (! CollectionSites::includes($root, $site->handle())) {
            return ['error' => 'site_not_in_collection', 'message' => CollectionSites::refusal($root, $site->handle())];
        }

        $existing = $root->existsIn($site->handle()) ? $root->in($site->handle()) : null;

        return [
            'source' => $source,
            'root' => $root,
            'site' => $site,
            'existing' => $existing,
        ];
    }

    /**
     * The role must be able to view the source and edit the localization. A
     * localization that does not exist yet is checked unsaved, so access to
     * the target site counts as well.
     *
     * @return array<string, mixed>|null
     */
    private function refuseLocalization(Entry $source, Entry $root, string $targetSite): ?array
    {
        $target = $root->existsIn($targetSite) ? $root->in($targetSite) : $root->makeLocalization($targetSite);

        return $this->context->refuse('view', $source) ?? $this->context->refuse('edit', $target);
    }

    /**
     * @return array<int, array{id: string, terms: array<string, string>}>
     */
    private function glossaryPayload(): array
    {
        return $this->glossary->entries();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function stylePayload(): array
    {
        $rules = [];
        foreach ($this->styleRules->rules() as $lang => $rule) {
            $rules[$lang] = $rule['instructions'];
        }

        return $rules;
    }

    private function previewMessage(string $action, Entry $source, Entry $root, string $targetName, ?Entry $existing, bool $published, int $segmentCount): string
    {
        $verb = $action === 'update' ? 'update the existing' : 'create a new';
        $publish = $published ? 'It will be published, because the source entry is published.' : 'It will stay unpublished, because the source entry is unpublished.';
        $existingId = $existing ? ' Existing entry '.$existing->id().' is overwritten.' : '';

        return 'This will '.$verb.' '.$targetName.' localization of "'.EntryLabel::for($source).'" (site '.$source->locale().'). '
            .'The localization stays linked to origin '.$root->id().'. '.$publish.$existingId.' '
            .$segmentCount.' segment(s) will be replaced with the translations you supply. '
            .'Translate the segments yourself. Nothing is saved until save_localization is called with this confirmation_token and confirmed true, after the user agrees.';
    }

    private function tokenKey(string $token): string
    {
        return 'statamic-ai-assistant:mcp-preview:'.$token;
    }
}
