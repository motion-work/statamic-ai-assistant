<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * Translation operations for an external model (Claude).
 *
 * The model translates the text with its own account. These methods only
 * read Statamic, describe the localization they would save, and write the
 * strings the model sends back. DeepL and Infomaniak are not called.
 */
class ClaudeTranslationBridge
{
    private const TOKEN_TTL_MINUTES = 30;

    private ?UserContract $actor = null;

    public function __construct(
        private EntryTranslator $translator,
        private SiteLanguageResolver $sites,
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private ContentCommitter $commits,
    ) {}

    public function setActor(?UserContract $user): void
    {
        $this->actor = $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function listSites(): array
    {
        $sites = [];
        foreach (Site::all() as $site) {
            $sites[] = [
                'handle' => $site->handle(),
                'locale' => $site->locale(),
                'name' => $site->name(),
            ];
        }

        return ['sites' => $sites];
    }

    /**
     * @return array<string, mixed>
     */
    public function findEntries(string $query, ?string $collection = null): array
    {
        $needle = mb_strtolower(trim($query));
        $entries = EntryFacade::query();
        if (is_string($collection) && $collection !== '') {
            $entries->where('collection', $collection);
        }

        $matches = [];
        foreach ($entries->get() as $entry) {
            $title = mb_strtolower(EntryLabel::for($entry));
            $slug = mb_strtolower((string) $entry->slug());
            if ($needle !== '' && ! str_contains($title, $needle) && ! str_contains($slug, $needle) && ! str_contains((string) $entry->id(), $needle)) {
                continue;
            }
            $matches[] = $this->entrySummary($entry);
            if (count($matches) >= 20) {
                break;
            }
        }

        return ['entries' => $matches];
    }

    /**
     * @return array<string, mixed>
     */
    public function readEntry(string $entryId): array
    {
        $entry = $this->findEntry($entryId);
        if (isset($entry['error'])) {
            return $entry;
        }

        return array_merge($this->entrySummary($entry), [
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
        $segments = $this->translator->externalSegments($source);
        $token = (string) Str::uuid();

        Cache::put($this->tokenKey($token), [
            'source_id' => (string) $source->id(),
            'root_id' => (string) $root->id(),
            'target_site' => $site->handle(),
            'segment_ids' => array_column($segments, 'id'),
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $action = $existing ? 'update' : 'create';
        $published = (bool) $root->published();
        $targetName = $site->name();

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
            'action' => $action,
            'source' => $this->entrySummary($source),
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
        if (! is_array($plan)) {
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
        $this->commits->commit('Translate "'.EntryLabel::for($saved).'" into '.$plan['target_site'], $this->actor);

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
        if (! $confirmed) {
            return [
                'saved' => false,
                'message' => 'Nothing was saved. This replaces the whole glossary. Show the next table to the user, then call again with confirmed true.',
                'current' => $this->glossary->entries(),
                'next' => $entries,
            ];
        }

        $saved = $this->glossary->save($entries);
        $this->commits->commit('Update the translation glossary', $this->actor);

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
        if (! $confirmed) {
            return [
                'saved' => false,
                'message' => 'Nothing was saved. Pass the complete rule set; omitted languages are removed. Show it to the user, then call again with confirmed true.',
                'current' => $this->readStyleRules()['rules'],
                'next' => $instructionsByLang,
            ];
        }

        $saved = $this->styleRules->save($instructionsByLang);
        $this->commits->commit('Update the translation style rules', $this->actor);
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
     * @return array<string, mixed>|Entry
     */
    private function findEntry(string $entryId): array|Entry
    {
        $id = trim($entryId);
        if ($id === '') {
            return ['error' => 'missing_entry', 'message' => 'Pass an entry id from find_entries.'];
        }

        $entry = EntryFacade::find($id);
        if (! $entry) {
            return ['error' => 'missing_entry', 'message' => 'No entry has id '.$id.'.'];
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePair(string $entryId, string $target): array
    {
        $source = $this->findEntry($entryId);
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

        $existing = $root->existsIn($site->handle()) ? $root->in($site->handle()) : null;

        return [
            'source' => $source,
            'root' => $root,
            'site' => $site,
            'existing' => $existing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entrySummary(Entry $entry): array
    {
        return [
            'entry_id' => (string) $entry->id(),
            'title' => EntryLabel::for($entry),
            'site' => (string) $entry->locale(),
            'collection' => $entry->collectionHandle(),
            'slug' => (string) $entry->slug(),
            'published' => (bool) $entry->published(),
            'origin_entry_id' => $entry->hasOrigin() && $entry->origin() ? (string) $entry->origin()->id() : null,
            'edit_url' => $entry->editUrl(),
        ];
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
