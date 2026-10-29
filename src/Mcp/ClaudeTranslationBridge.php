<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * Translation and editing operations for an external model (Claude).
 *
 * The model writes the text with its own account. These methods only read
 * Statamic, describe the change they would save, and write the strings the
 * model sends back. DeepL and Infomaniak are not called.
 *
 * A signed-in account only sees entries its Control Panel role may view, and
 * only writes where the role may edit. Without an account (the local stdio
 * server) reads and translations are open, and in-place edits are refused.
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
            if ($this->actor !== null && ! $this->actor->can('view', $entry)) {
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
        if (($denied = $this->refuse('view', $entry)) !== null) {
            return $denied;
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
            'user_id' => $this->actor !== null ? (string) $this->actor->id() : null,
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
        if (! is_array($plan) || ($plan['user_id'] ?? null) !== ($this->actor !== null ? (string) $this->actor->id() : null)) {
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
     * Without edits, list the entry's editable segments. With edits, describe
     * the change and return a confirmation_token. Writes nothing.
     *
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function previewEntryEdit(string $entryId, array $edits): array
    {
        $entry = $this->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if (($denied = $this->refuseEdit($entry)) !== null) {
            return $denied;
        }

        $segments = $this->translator->editableSegments($entry);
        if ($edits === []) {
            return array_merge($this->entrySummary($entry), [
                'saved' => false,
                'segments' => $segments,
                'message' => 'Call preview_entry_edit again with edits: a map of segment id to the new text. Only the segments you pass change. HTML segments must stay HTML.',
            ]);
        }

        $byId = array_column($segments, null, 'id');
        $changes = [];
        $unknown = [];
        foreach ($edits as $id => $text) {
            $id = (string) $id;
            if (! isset($byId[$id])) {
                $unknown[] = $id;

                continue;
            }
            if (is_string($text) && $text !== $byId[$id]['text']) {
                $changes[$id] = ['id' => $id, 'before' => $byId[$id]['text'], 'after' => $text, 'html' => $byId[$id]['html']];
            }
        }

        if ($unknown !== []) {
            return [
                'saved' => false,
                'error' => 'unknown_segments',
                'unknown' => $unknown,
                'message' => 'These segment ids are not in the entry. Call preview_entry_edit without edits to list them. Nothing was saved.',
            ];
        }
        if ($changes === []) {
            return ['saved' => false, 'error' => 'no_changes', 'message' => 'The edits match the current text. Nothing would change.'];
        }

        $token = (string) Str::uuid();
        Cache::put($this->editTokenKey($token), [
            'entry_id' => (string) $entry->id(),
            'user_id' => (string) $this->actor->id(),
            'edits' => array_map(fn ($change) => $change['after'], $changes),
            'fingerprint' => $this->fingerprint($segments),
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
            'entry' => $this->entrySummary($entry),
            'changes' => array_values($changes),
            'message' => 'This will change '.count($changes).' segment(s) of "'.EntryLabel::for($entry).'" (site '.$entry->locale().') in place. '
                .'Everything else stays as it is, including the slug and the published state. '
                .'Show the changes to the user. Nothing is saved until save_entry_edit is called with this confirmation_token and confirmed true, after they agree.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveEntryEdit(string $token, bool $confirmed): array
    {
        if (! $confirmed) {
            return [
                'saved' => false,
                'error' => 'not_confirmed',
                'message' => 'Nothing was saved. Show the changes to the user and call save_entry_edit again with confirmed true only after they agree.',
            ];
        }

        $plan = Cache::get($this->editTokenKey($token));
        if (! is_array($plan) || $this->actor === null || $plan['user_id'] !== (string) $this->actor->id()) {
            return [
                'saved' => false,
                'error' => 'preview_required',
                'message' => 'Call preview_entry_edit with your edits first and reuse its confirmation_token. Nothing was saved.',
            ];
        }

        $entry = EntryFacade::find($plan['entry_id']);
        if (! $entry) {
            return ['saved' => false, 'error' => 'missing_entry', 'message' => 'The entry from the preview no longer exists. Nothing was saved.'];
        }
        if (($denied = $this->refuseEdit($entry)) !== null) {
            return $denied;
        }
        if ($this->fingerprint($this->translator->editableSegments($entry)) !== $plan['fingerprint']) {
            Cache::forget($this->editTokenKey($token));

            return [
                'saved' => false,
                'error' => 'entry_changed',
                'message' => 'Someone changed this entry after the preview. Read it again and preview the edit anew. Nothing was saved.',
            ];
        }

        $this->translator->applySegmentEdits($entry, $plan['edits']);
        $entry->set('updated_by', $this->actor->id());
        $entry->set('updated_at', now()->timestamp);
        $entry->save();
        Cache::forget($this->editTokenKey($token));

        return array_merge($this->entrySummary($entry), [
            'saved' => true,
            'changed_segments' => count($plan['edits']),
        ]);
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
     * In-place edits always need an account, so updated_by and the commit
     * name a person.
     *
     * @return array<string, mixed>|null
     */
    private function refuseEdit(Entry $entry): ?array
    {
        if ($this->actor === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Editing needs a signed-in Control Panel account.'];
        }

        return $this->refuse('edit', $entry);
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

        return $this->refuse('view', $source) ?? $this->refuse('edit', $target);
    }

    /**
     * The glossary and style rules apply to every translation on the site, so
     * changing them needs the role permission, as on the Control Panel page.
     *
     * @return array<string, mixed>|null
     */
    private function refuseGlossary(): ?array
    {
        if ($this->actor === null || GlossaryAccess::canEdit($this->actor)) {
            return null;
        }

        return [
            'saved' => false,
            'error' => 'forbidden',
            'message' => 'Your Control Panel role cannot change the glossary and style rules. A super admin can grant "Edit translation glossary and style rules" under Users, Roles. Nothing was saved.',
        ];
    }

    /**
     * Statamic's entry policy, as in the Control Panel: the collection
     * permission, access to the entry's site, and other authors' entries.
     *
     * @return array<string, mixed>|null
     */
    private function refuse(string $ability, Entry $entry): ?array
    {
        if ($this->actor === null || $this->actor->can($ability, $entry)) {
            return null;
        }

        return [
            'saved' => false,
            'error' => 'forbidden',
            'message' => 'Your Control Panel role cannot '.$ability.' '.$entry->collectionHandle().' entries on the '.$entry->locale().' site. Nothing was saved.',
        ];
    }

    /**
     * @param  array<int, array{id: string, text: string, html: bool}>  $segments
     */
    private function fingerprint(array $segments): string
    {
        return hash('sha256', (string) json_encode($segments));
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

    private function editTokenKey(string $token): string
    {
        return 'statamic-ai-assistant:mcp-edit:'.$token;
    }
}
