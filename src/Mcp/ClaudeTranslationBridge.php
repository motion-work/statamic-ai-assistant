<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntryStructureSerializer;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use MotionWork\StatamicAiAssistant\Support\SiteLanguageResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Actions\DuplicateEntry;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Collection;
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

    /**
     * Also the request's logged-in user, so policies, updated_by, git commits
     * and Statamic's own actions see the account as in the Control Panel.
     */
    public function setActor(?UserContract $user): void
    {
        $this->actor = $user;
        if ($user !== null) {
            auth()->setUser($user);
        }
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
     * Without edits or a slug, list the entry's editable segments. With them,
     * describe the change and return a confirmation_token. Writes nothing.
     *
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function previewEntryEdit(string $entryId, array $edits, ?string $slug = null): array
    {
        $entry = $this->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if (($denied = $this->refuseEdit($entry)) !== null) {
            return $denied;
        }

        $segments = $this->translator->editableSegments($entry);
        if ($edits === [] && ($slug === null || trim($slug) === '')) {
            return array_merge($this->entrySummary($entry), [
                'saved' => false,
                'segments' => $segments,
                'message' => 'Call preview_entry_edit again with edits (a map of segment id to the new text) and/or a new slug. Only what you pass changes. HTML segments must stay HTML.',
            ]);
        }

        $byId = array_column($segments, null, 'id');
        $changes = [];
        $unknown = [];
        $invalid = [];
        foreach ($edits as $id => $new) {
            $id = (string) $id;
            if (! isset($byId[$id])) {
                $unknown[] = $id;

                continue;
            }
            $segment = $byId[$id];
            if (isset($segment['kind'])) {
                $checked = $this->checkValue($segment, $new);
                if (isset($checked['error'])) {
                    $invalid[$id] = $checked['error'];
                } elseif ($checked['value'] !== $segment['value']) {
                    $changes[$id] = [
                        'id' => $id,
                        'kind' => $segment['kind'],
                        'field' => $segment['label'] ?? $segment['field'],
                        'location' => $segment['location'],
                        'before' => $segment['value'],
                        'after' => $checked['value'],
                    ];
                }
            } elseif (is_string($new) && $new !== $segment['text']) {
                $changes[$id] = ['id' => $id, 'before' => $segment['text'], 'after' => $new, 'html' => $segment['html']];
            }
        }

        if ($invalid !== []) {
            return [
                'saved' => false,
                'error' => 'invalid_values',
                'invalid' => $invalid,
                'message' => 'Some values are not allowed for their field. Nothing was saved.',
            ];
        }

        if ($unknown !== []) {
            return [
                'saved' => false,
                'error' => 'unknown_segments',
                'unknown' => $unknown,
                'message' => 'These segment ids are not in the entry. Call preview_entry_edit without edits to list them. Nothing was saved.',
            ];
        }

        $newSlug = null;
        if ($slug !== null && trim($slug) !== '') {
            $newSlug = $this->normalizeSlug($entry, $slug);
            if (is_array($newSlug)) {
                return $newSlug;
            }
            if ($newSlug === (string) $entry->slug()) {
                $newSlug = null;
            }
        }

        if ($changes === [] && $newSlug === null) {
            return ['saved' => false, 'error' => 'no_changes', 'message' => 'The edits match the current entry. Nothing would change.'];
        }

        $token = (string) Str::uuid();
        Cache::put($this->editTokenKey($token), [
            'entry_id' => (string) $entry->id(),
            'user_id' => (string) $this->actor->id(),
            'edits' => array_map(fn ($change) => $change['after'], $changes),
            'slug' => $newSlug,
            'fingerprint' => $this->fingerprint($entry, $segments),
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $parts = [];
        if ($changes !== []) {
            $parts[] = count($changes).' field(s)';
        }
        if ($newSlug !== null) {
            $parts[] = 'the slug from "'.$entry->slug().'" to "'.$newSlug.'"';
        }
        $address = $newSlug !== null ? 'The new slug changes the page\'s web address, so old links to it stop working unless a redirect is added. ' : '';

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
            'entry' => $this->entrySummary($entry),
            'changes' => array_values($changes),
            'slug' => $newSlug !== null ? ['before' => (string) $entry->slug(), 'after' => $newSlug] : null,
            'message' => 'This will change '.implode(' and ', $parts).' of "'.EntryLabel::for($entry).'" (site '.$entry->locale().'). '
                .$address
                .'Everything else stays as it is, including the published state. '
                .'Show the changes to the user. Nothing is saved until save_entry_edit is called with this confirmation_token and confirmed true, after they agree.',
        ];
    }

    /**
     * A toggle takes true or false, a choice field one of its options (a list
     * of them when it allows several), a link a URL, mailto:, tel:, a /path,
     * a #anchor or entry::<id> of an existing entry. Null clears a choice or
     * a link.
     *
     * @param  array<string, mixed>  $segment
     * @return array{value: mixed}|array{error: string}
     */
    private function checkValue(array $segment, mixed $new): array
    {
        if ($segment['kind'] === 'toggle') {
            $bool = is_bool($new) ? $new : filter_var($new, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $bool === null ? ['error' => 'A toggle takes true or false.'] : ['value' => $bool];
        }

        if ($new === null || $new === '' || $new === []) {
            return ['value' => null];
        }

        if ($segment['kind'] === 'choice') {
            $picked = $segment['multiple'] ? (is_array($new) ? array_values($new) : [$new]) : $new;
            if (! $segment['multiple'] && ! is_scalar($picked)) {
                return ['error' => 'This field takes one option.'];
            }
            foreach ((array) $picked as $option) {
                if (! is_scalar($option) || (! $segment['custom_values_allowed'] && ! array_key_exists((string) $option, $segment['options']))) {
                    return ['error' => 'Pick from the options: '.implode(', ', array_keys($segment['options'])).'.'];
                }
            }

            return ['value' => is_array($picked) ? array_map('strval', $picked) : (string) $picked];
        }

        if (! is_string($new)) {
            return ['error' => 'A link is a string.'];
        }
        $link = trim($new);
        if (str_starts_with($link, 'entry::')) {
            return EntryFacade::find(substr($link, 7))
                ? ['value' => $link]
                : ['error' => 'No entry has id '.substr($link, 7).'. Use an id from find_entries.'];
        }
        if (preg_match('~^(https?://\S+|mailto:\S+|tel:[+\d\s()/-]+|/\S*|#\S+)$~i', $link) !== 1) {
            return ['error' => 'A link is a full URL, mailto:, tel:, a /path, a #anchor or entry::<id>.'];
        }

        return ['value' => $link];
    }

    /**
     * Format a slug as Statamic's slug field does, in the site's language,
     * and refuse one another entry in the same collection and site has.
     *
     * @return string|array<string, mixed>
     */
    private function normalizeSlug(Entry $entry, string $slug): string|array
    {
        $normalized = Str::slug($slug, '-', $entry->site()->lang());
        if ($normalized === '') {
            return ['saved' => false, 'error' => 'invalid_slug', 'message' => 'The slug needs letters or numbers. Nothing was saved.'];
        }

        $taken = EntryFacade::query()
            ->where('collection', $entry->collectionHandle())
            ->where('site', $entry->locale())
            ->where('slug', $normalized)
            ->get()
            ->contains(fn ($other) => (string) $other->id() !== (string) $entry->id());
        if ($taken) {
            return ['saved' => false, 'error' => 'slug_taken', 'message' => 'Another '.$entry->collectionHandle().' entry on the '.$entry->locale().' site already uses the slug "'.$normalized.'". Nothing was saved.'];
        }

        return $normalized;
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
        if ($this->fingerprint($entry, $this->translator->editableSegments($entry)) !== $plan['fingerprint']) {
            Cache::forget($this->editTokenKey($token));

            return [
                'saved' => false,
                'error' => 'entry_changed',
                'message' => 'Someone changed this entry after the preview. Read it again and preview the edit anew. Nothing was saved.',
            ];
        }

        if ($plan['slug'] !== null) {
            $slug = $this->normalizeSlug($entry, $plan['slug']);
            if (is_array($slug)) {
                return $slug;
            }
            $entry->slug($slug);
        }

        $this->translator->applySegmentEdits($entry, $plan['edits']);
        $entry->updateLastModified($this->actor);
        $entry->save();
        Cache::forget($this->editTokenKey($token));

        return array_merge($this->entrySummary($entry), [
            'saved' => true,
            'changed_segments' => count($plan['edits']),
            'slug_changed' => $plan['slug'] !== null,
        ]);
    }

    /**
     * Statamic's own Duplicate action, as the signed-in account: the copy is
     * unpublished, gets "(Duplicated)" and a new slug, keeps its place in the
     * page tree, and brings along the localizations the account may create.
     * A localization is duplicated from its root entry, as in the Control Panel.
     *
     * @return array<string, mixed>
     */
    public function duplicateEntry(string $entryId, bool $confirmed): array
    {
        $entry = $this->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if (($denied = $this->refuseCreate($entry)) !== null) {
            return $denied;
        }

        $root = $entry->hasOrigin() && $entry->root() ? $entry->root() : $entry;
        if (! $confirmed) {
            $localizations = $root->descendants()->count();

            return [
                'saved' => false,
                'entry' => $this->entrySummary($root),
                'message' => 'This will create an unpublished copy of "'.EntryLabel::for($root).'" (site '.$root->locale().') with "(Duplicated)" in the title and a new slug'
                    .($localizations > 0 ? ', plus copies of its '.$localizations.' localization(s) where your role may create entries' : '')
                    .($root !== $entry ? '. You picked a localization, so its source entry is copied, as in the Control Panel' : '')
                    .'. Show this to the user and call duplicate_entry again with confirmed true only after they agree.',
            ];
        }

        $before = $this->copiesOf($root);
        (new DuplicateEntry)->context(['view' => 'list'])->run(collect([$entry]), []);
        $copyId = array_values(array_diff($this->copiesOf($root), $before))[0] ?? null;
        $copy = $copyId !== null ? EntryFacade::find($copyId) : null;
        if (! $copy) {
            return ['saved' => false, 'error' => 'not_duplicated', 'message' => 'The entry could not be duplicated.'];
        }

        $localizations = EntryFacade::query()->where('origin', $copy->id())->get();

        return array_merge($this->entrySummary($copy), [
            'saved' => true,
            'duplicated_from' => (string) $root->id(),
            'localizations' => $localizations->map(fn ($e) => $this->entrySummary($e))->values()->all(),
            'message' => 'The copy is unpublished. Use preview_entry_edit to change its text, and set_published to publish it.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPublished(string $entryId, bool $published, bool $confirmed): array
    {
        $entry = $this->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if ($this->actor === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Publishing needs a signed-in Control Panel account.'];
        }
        if (($denied = $this->refuse('publish', $entry)) !== null) {
            return $denied;
        }

        $verb = $published ? 'publish' : 'unpublish';
        if ((bool) $entry->published() === $published) {
            return array_merge($this->entrySummary($entry), [
                'saved' => false,
                'message' => 'The entry is already '.($published ? 'published' : 'unpublished').'. Nothing would change.',
            ]);
        }
        if (! $confirmed) {
            return array_merge($this->entrySummary($entry), [
                'saved' => false,
                'message' => 'This will '.$verb.' "'.EntryLabel::for($entry).'" (site '.$entry->locale().') only'
                    .($published ? ', so it shows on the website' : ', so it no longer shows on the website')
                    .'. Other languages keep their own state. Show this to the user and call set_published again with confirmed true only after they agree.',
            ]);
        }

        $entry->published($published);
        $entry->updateLastModified($this->actor);
        $entry->save();

        return array_merge($this->entrySummary($entry), ['saved' => true]);
    }

    /**
     * Without arguments, the collections the account can see. With a
     * collection (or an entry to refactor), the brief for composing one: the
     * agent's field schema with block hints, site instructions, glossary and
     * style rules, plus the entry's current content when refactoring.
     *
     * @return array<string, mixed>
     */
    public function contentModel(?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId): array
    {
        if ($entryId !== null && $entryId !== '') {
            $entry = $this->findEntry($entryId);
            if (is_array($entry)) {
                return $entry;
            }
            if (($denied = $this->refuse('view', $entry)) !== null) {
                return $denied;
            }
            $snapshot = app(EntryStructureSerializer::class)->serialize($entry, $entry->blueprint(), $incomplete, false);

            return array_merge($this->entrySummary($entry), [
                'blueprint' => $entry->blueprint()->handle(),
                'brief' => $this->generator()->compositionBrief($entry->blueprint(), (string) $entry->locale(), $this->blockTypesIn($entry->data()->all())),
                'current_content' => $snapshot,
                'message' => 'current_content is the entry as stored, one field per line. Compose the refactored entry as one JSON object and pass it as data to preview_compose_entry with this entry_id. Fields you leave out stay as they are; a field you include replaces it whole, so include every block you want to keep. Refer to a block you keep by its id: send it back with its id (and any fields you change) and it is kept exactly as stored, including images and links you did not touch. Blocks without an id are new and follow the brief\'s format. Ask the user whether to change this entry in place or save the result as an unpublished copy, and pass mode accordingly.',
            ]);
        }

        if ($collectionHandle === null || $collectionHandle === '') {
            $collections = [];
            foreach (Collection::all() as $collection) {
                $blueprints = $collection->entryBlueprints()->reject->hidden()->map(fn ($b) => ['handle' => $b->handle(), 'title' => $b->title()])->values()->all();
                $sites = $collection->sites()->filter(fn ($site) => $this->actor === null || $this->actor->can('create', [Entry::class, $collection, Site::get($site)]))->values()->all();
                if ($blueprints === [] || $sites === []) {
                    continue;
                }
                $collections[] = ['handle' => $collection->handle(), 'title' => $collection->title(), 'blueprints' => $blueprints, 'sites' => $sites];
            }

            return [
                'collections' => $collections,
                'message' => 'Call get_content_model again with a collection (and optionally blueprint and site) for the brief to compose an entry.',
            ];
        }

        $resolved = $this->resolveTarget($collectionHandle, $blueprintHandle, $siteHandle);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        return [
            'collection' => $resolved['collection']->handle(),
            'blueprint' => $resolved['blueprint']->handle(),
            'site' => $resolved['site']->handle(),
            'brief' => $this->generator()->compositionBrief($resolved['blueprint'], $resolved['site']->handle()),
            'message' => 'Choose the blocks from set_layout_catalog by their description and when-to-use rules, call get_block_schemas for their fields, then compose the entry as one JSON object in the brief\'s format from the user\'s goal and content, and pass it as data to preview_compose_entry. Follow the site instructions, glossary and style rules in the brief.',
        ];
    }

    /**
     * The fields of the blocks chosen from the catalog, for a collection or
     * for the entry being refactored.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<string, mixed>
     */
    public function blockSchemas(?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId, array $blocks): array
    {
        $handles = array_values(array_unique(array_filter(array_map('strval', $blocks))));
        if ($handles === []) {
            return ['error' => 'missing_blocks', 'message' => 'Pass the handles of the blocks you chose from set_layout_catalog.'];
        }

        if ($entryId !== null && $entryId !== '') {
            $entry = $this->findEntry($entryId);
            if (is_array($entry)) {
                return $entry;
            }
            if (($denied = $this->refuse('view', $entry)) !== null) {
                return $denied;
            }
            $blueprint = $entry->blueprint();
            $site = (string) $entry->locale();
        } else {
            $resolved = $this->resolveTarget((string) $collectionHandle, $blueprintHandle, $siteHandle);
            if (isset($resolved['error'])) {
                return $resolved;
            }
            $blueprint = $resolved['blueprint'];
            $site = $resolved['site']->handle();
        }

        return $this->generator()->blockSchemas($blueprint, $site, $handles);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function blockTypesIn(array $data): array
    {
        $types = [];
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    if (is_array($item) && is_string($item['type'] ?? null)) {
                        $types[] = $item['type'];
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Map the composed JSON as the agent would and describe the result:
     * a new entry, an unpublished copy of an existing one, or the existing
     * entry changed in place. Writes nothing.
     *
     * @param  array<string, mixed>  $composed
     * @return array<string, mixed>
     */
    public function previewComposeEntry(array $composed, ?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId, ?string $mode, ?string $parentId): array
    {
        if ($composed === []) {
            return ['saved' => false, 'error' => 'missing_data', 'message' => 'Pass the composed entry as data. Call get_content_model for its format.'];
        }

        $source = null;
        if ($entryId !== null && $entryId !== '') {
            $source = $this->findEntry($entryId);
            if (is_array($source)) {
                return $source;
            }
            if (! in_array($mode, ['in_place', 'copy'], true)) {
                return ['saved' => false, 'error' => 'mode_required', 'message' => 'Ask the user whether to change this entry in place or save the result as an unpublished copy, then pass mode "in_place" or "copy".'];
            }
            $denied = $mode === 'in_place' ? $this->refuseEdit($source) : $this->refuseCreate($source);
            if ($denied !== null) {
                return $denied;
            }
            $collection = $source->collection();
            $blueprint = $source->blueprint();
            $site = $source->site();
        } else {
            $mode = 'create';
            $resolved = $this->resolveTarget((string) $collectionHandle, $blueprintHandle, $siteHandle);
            if (isset($resolved['error'])) {
                return $resolved;
            }
            ['collection' => $collection, 'blueprint' => $blueprint, 'site' => $site] = $resolved;
            if ($this->actor === null) {
                return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Creating entries needs a signed-in Control Panel account.'];
            }
            if (! $this->actor->can('create', [Entry::class, $collection, $site])) {
                return ['saved' => false, 'error' => 'forbidden', 'message' => 'Your Control Panel role cannot create '.$collection->handle().' entries on the '.$site->handle().' site. Nothing was saved.'];
            }
        }

        $slots = [];
        if ($source !== null) {
            [$composed, $slots] = $this->splitKeptBlocks($composed, $source->data()->all());
        }
        $mapped = $this->generator()->mapComposedData($composed, $blueprint, $site->handle(), $source !== null);
        $warnings = $mapped['warnings'];
        $data = $source !== null
            ? $this->generator()->mergeComposedData($source->data()->all(), $this->reassembleBlocks($mapped['data'], $slots, $warnings))
            : $mapped['data'];

        // A dated collection keeps the date on the entry, not in its data.
        $date = null;
        if ($collection->dated()) {
            $date = $source?->date() ?? $this->parseDate($data['date'] ?? null) ?? now();
            unset($data['date']);
        }

        $problems = $this->validationProblems($blueprint, $date ? $data + ['date' => $date->format('Y-m-d H:i')] : $data);
        if ($problems !== []) {
            return [
                'saved' => false,
                'error' => 'invalid_entry',
                'problems' => $problems,
                'warnings' => $warnings,
                'message' => 'The composed entry does not pass the blueprint\'s validation. Fix these fields and preview again. Nothing was saved.',
            ];
        }

        $parent = null;
        if ($mode === 'create' && $parentId !== null && $parentId !== '') {
            $parent = EntryFacade::find($parentId);
            if (! $parent || $parent->collectionHandle() !== $collection->handle() || $parent->locale() !== $site->handle()) {
                return ['saved' => false, 'error' => 'invalid_parent', 'message' => 'The parent must be an entry of the same collection and site. Nothing was saved.'];
            }
        }

        $title = is_string($data['title'] ?? null) && $data['title'] !== '' ? $data['title'] : ($source ? EntryLabel::for($source) : 'Untitled');
        $slug = $mode === 'in_place' ? (string) $source->slug() : $this->freeSlug($collection->handle(), $site, $title);

        $token = (string) Str::uuid();
        Cache::put($this->composeTokenKey($token), [
            'mode' => $mode,
            'user_id' => $this->actor !== null ? (string) $this->actor->id() : null,
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->handle(),
            'site' => $site->handle(),
            'entry_id' => $source ? (string) $source->id() : null,
            'parent_id' => $parent ? (string) $parent->id() : null,
            'slug' => $slug,
            'data' => $data,
            'date' => $date?->format('Y-m-d H:i'),
            'fingerprint' => $source ? hash('sha256', (string) json_encode($source->data()->all())) : null,
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $what = match ($mode) {
            'in_place' => 'change "'.EntryLabel::for($source).'" in place'.($source->published() ? '; it is published, so the live page changes right away' : ''),
            'copy' => 'save the result as a new unpublished copy of "'.EntryLabel::for($source).'" next to it in the page tree; the original stays as it is',
            default => 'create a new unpublished '.$collection->handle().' entry'.($parent ? ' under "'.EntryLabel::for($parent).'"' : ''),
        };

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
            'mode' => $mode,
            'title' => $title,
            'slug' => $slug,
            'site' => $site->handle(),
            'outline' => $this->outline($blueprint, $data),
            'warnings' => array_values($warnings),
            'message' => 'This will '.$what.' (site '.$site->handle().'). Show the outline and any warnings to the user. Nothing is saved until save_compose_entry is called with this confirmation_token and confirmed true, after they agree.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveComposeEntry(string $token, bool $confirmed): array
    {
        if (! $confirmed) {
            return ['saved' => false, 'error' => 'not_confirmed', 'message' => 'Nothing was saved. Show the outline to the user and call save_compose_entry again with confirmed true only after they agree.'];
        }

        $plan = Cache::get($this->composeTokenKey($token));
        if (! is_array($plan) || $plan['user_id'] !== ($this->actor !== null ? (string) $this->actor->id() : null)) {
            return ['saved' => false, 'error' => 'preview_required', 'message' => 'Call preview_compose_entry first and reuse its confirmation_token. Nothing was saved.'];
        }

        $collection = Collection::findByHandle($plan['collection']);
        $site = Site::get($plan['site']);
        $source = $plan['entry_id'] ? EntryFacade::find($plan['entry_id']) : null;
        if (! $collection || ! $site || ($plan['entry_id'] && ! $source)) {
            return ['saved' => false, 'error' => 'missing_entry', 'message' => 'The collection or entry from the preview no longer exists. Nothing was saved.'];
        }

        if ($plan['mode'] === 'in_place') {
            if (($denied = $this->refuseEdit($source)) !== null) {
                return $denied;
            }
            if (hash('sha256', (string) json_encode($source->data()->all())) !== $plan['fingerprint']) {
                Cache::forget($this->composeTokenKey($token));

                return ['saved' => false, 'error' => 'entry_changed', 'message' => 'Someone changed this entry after the preview. Read it again and compose anew. Nothing was saved.'];
            }
            $source->data($plan['data']);
            $source->updateLastModified($this->actor);
            $source->save();
            Cache::forget($this->composeTokenKey($token));

            return array_merge($this->entrySummary($source), ['saved' => true, 'mode' => 'in_place']);
        }

        if (! $this->actor || ! $this->actor->can('create', [Entry::class, $collection, $site])) {
            return ['saved' => false, 'error' => 'forbidden', 'message' => 'Your Control Panel role cannot create '.$collection->handle().' entries on the '.$site->handle().' site. Nothing was saved.'];
        }

        $entry = EntryFacade::make()
            ->collection($collection)
            ->blueprint($plan['blueprint'])
            ->locale($site->handle())
            ->published(false)
            ->data($plan['data']);
        if ($collection->requiresSlugs()) {
            $entry->slug(EntryFacade::query()->where('collection', $collection->handle())->where('site', $site->handle())->where('slug', $plan['slug'])->count() > 0
                ? $this->freeSlug($collection->handle(), $site, $plan['slug'])
                : $plan['slug']);
        }
        if ($collection->dated()) {
            $entry->date($plan['date'] ? \Illuminate\Support\Carbon::parse($plan['date']) : now());
        }
        $entry->updateLastModified($this->actor);
        $entry->save();

        if ($collection->hasStructure()) {
            $tree = $collection->structure()->in($site->handle());
            $parentId = $plan['mode'] === 'copy' ? $this->parentInTree($source) : $plan['parent_id'];
            $parentId ? $tree->appendTo($parentId, $entry) : $tree->append($entry);
            $tree->save();
        }
        Cache::forget($this->composeTokenKey($token));

        return array_merge($this->entrySummary($entry), [
            'saved' => true,
            'mode' => $plan['mode'],
            'message' => 'Saved unpublished. Review it in the Control Panel (edit_url), then publish it with set_published when the user agrees.',
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

    private function generator(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTarget(string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle): array
    {
        $collection = Collection::findByHandle($collectionHandle);
        if (! $collection) {
            return ['saved' => false, 'error' => 'missing_collection', 'message' => 'No collection has the handle '.$collectionHandle.'. Call get_content_model without arguments to list them.'];
        }

        $blueprints = $collection->entryBlueprints()->reject->hidden();
        $blueprint = $blueprintHandle ? $blueprints->first(fn ($b) => $b->handle() === $blueprintHandle) : $blueprints->first();
        if (! $blueprint) {
            return ['saved' => false, 'error' => 'missing_blueprint', 'message' => 'The '.$collectionHandle.' collection has no blueprint '.$blueprintHandle.'.'];
        }

        $site = $siteHandle ? Site::get($siteHandle) : Site::get($collection->sites()->first());
        if (! $site || ! $collection->sites()->contains($site->handle())) {
            return ['saved' => false, 'error' => 'missing_site', 'message' => 'The '.$collectionHandle.' collection is not available on site '.$siteHandle.'.'];
        }

        return ['collection' => $collection, 'blueprint' => $blueprint, 'site' => $site];
    }

    /**
     * Blocks the model sends back with their stored id are kept as stored;
     * only the fields it changed go through the mapper and are laid over
     * them. Running a stored block through the mapper would lose what it
     * does not round-trip (images, entry references, a disabled state).
     * New blocks are mapped whole.
     *
     * @param  array<string, mixed>  $composed
     * @param  array<string, mixed>  $existing
     * @return array{0: array<string, mixed>, 1: array<string, array<int, array<string, mixed>>>}
     */
    private function splitKeptBlocks(array $composed, array $existing): array
    {
        $slots = [];
        foreach ($composed as $handle => $list) {
            $stored = $existing[$handle] ?? null;
            if (! is_array($list) || ! array_is_list($list) || ! is_array($stored)) {
                continue;
            }
            $byId = [];
            foreach ($stored as $block) {
                if (is_array($block) && isset($block['id'], $block['type'])) {
                    $byId[(string) $block['id']] = $block;
                }
            }
            if ($byId === []) {
                continue;
            }

            $send = [];
            $fieldSlots = [];
            foreach ($list as $item) {
                $block = is_array($item) && isset($item['id']) ? ($byId[(string) $item['id']] ?? null) : null;
                if ($block === null || (isset($item['type']) && $item['type'] !== $block['type'])) {
                    $fieldSlots[] = ['new' => count($send), 'enabled' => $item['enabled'] ?? null];
                    $send[] = $item;

                    continue;
                }
                $changed = [];
                foreach ($item as $key => $value) {
                    if (! in_array($key, ['id', 'type', 'enabled'], true) && (! array_key_exists($key, $block) || $block[$key] != $value)) {
                        $changed[$key] = $value;
                    }
                }
                $fieldSlots[] = [
                    'stored' => $block,
                    'enabled' => $item['enabled'] ?? null,
                    'changed' => array_keys($changed),
                    'mapped' => $changed === [] ? null : count($send),
                ];
                if ($changed !== []) {
                    $send[] = ['type' => $block['type']] + $changed;
                }
            }

            $slots[$handle] = $fieldSlots;
            if ($send === []) {
                unset($composed[$handle]);
            } else {
                $composed[$handle] = $send;
            }
        }

        return [$composed, $slots];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, array<int, array<string, mixed>>>  $slots
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function reassembleBlocks(array $mapped, array $slots, array &$warnings): array
    {
        foreach ($slots as $handle => $fieldSlots) {
            $mappedList = is_array($mapped[$handle] ?? null) ? array_values($mapped[$handle]) : [];
            $blocks = [];
            foreach ($fieldSlots as $slot) {
                if (isset($slot['new'])) {
                    $block = $mappedList[$slot['new']] ?? null;
                    if ($block === null) {
                        $warnings[] = 'A new block in "'.$handle.'" could not be used and was left out.';

                        continue;
                    }
                } else {
                    $block = $slot['stored'];
                    $fresh = $slot['mapped'] !== null ? ($mappedList[$slot['mapped']] ?? []) : [];
                    foreach ($slot['changed'] as $key) {
                        if (array_key_exists($key, $fresh)) {
                            $block[$key] = $fresh[$key];
                        } else {
                            $warnings[] = 'The new value for "'.$key.'" in the '.$block['type'].' block could not be used; the stored value was kept.';
                        }
                    }
                }
                if ($slot['enabled'] !== null) {
                    $block['enabled'] = (bool) $slot['enabled'];
                }
                $blocks[] = $block;
            }
            $mapped[$handle] = $blocks;
        }

        return $mapped;
    }

    private function parseDate(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validationProblems(\Statamic\Fields\Blueprint $blueprint, array $data): array
    {
        // The Control Panel validates its form values, so validate the
        // preprocessed values rather than the stored ones (dates differ).
        try {
            $blueprint->fields()->addValues($data)->preProcess()->validator()->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return array_map(fn ($messages) => implode(' ', $messages), $e->errors());
        }

        return [];
    }

    private function freeSlug(string $collectionHandle, \Statamic\Sites\Site $site, string $title): string
    {
        $base = Str::slug($title, '-', $site->lang()) ?: 'entry';
        $slug = $base;
        for ($n = 2; EntryFacade::query()->where('collection', $collectionHandle)->where('site', $site->handle())->where('slug', $slug)->count() > 0; $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function parentInTree(Entry $entry): ?string
    {
        $page = $entry->structure()?->in($entry->locale())->find($entry->id());
        $parent = $page?->parent();
        if (! $parent || ($entry->structure()->expectsRoot() && $entry->structure()->in($entry->locale())->root()['entry'] === $parent->id())) {
            return null;
        }

        return (string) $parent->id();
    }

    /**
     * Blocks and headlines of the composed entry, for the user to review.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function outline(\Statamic\Fields\Blueprint $blueprint, array $data): array
    {
        $outline = [];
        foreach ($blueprint->fields()->all() as $field) {
            $value = $data[$field->handle()] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (in_array($field->type(), ['replicator', 'components'], true) && is_array($value)) {
                $blocks = [];
                foreach ($value as $set) {
                    if (is_array($set) && isset($set['type'])) {
                        $headline = $this->headline($set);
                        $blocks[] = $headline === '' ? $set['type'] : $set['type'].': '.$headline;
                    }
                }
                $outline[] = ['field' => $field->display(), 'blocks' => $blocks];
            } elseif (is_string($value)) {
                $outline[] = ['field' => $field->display(), 'value' => Str::limit(strip_tags($value), 120)];
            } elseif ($field->type() === 'bard' && is_array($value)) {
                $text = [];
                array_walk_recursive($value, function ($node, $key) use (&$text) {
                    if ($key === 'text' && is_string($node)) {
                        $text[] = $node;
                    }
                });
                $outline[] = ['field' => $field->display(), 'value' => Str::limit(implode(' ', $text), 120)];
            }
        }

        return $outline;
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function headline(array $set): string
    {
        foreach (['title', 'label', 'subtitle'] as $key) {
            if (is_string($set[$key] ?? null) && trim($set[$key]) !== '') {
                return Str::limit(trim($set[$key]), 90);
            }
        }

        return '';
    }

    /**
     * Statamic's Duplicate check: the role may create entries in this
     * collection on this site.
     *
     * @return array<string, mixed>|null
     */
    private function refuseCreate(Entry $entry): ?array
    {
        if ($this->actor === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Duplicating needs a signed-in Control Panel account.'];
        }
        if ($this->actor->can('create', [Entry::class, $entry->collection(), $entry->site()])) {
            return null;
        }

        return [
            'saved' => false,
            'error' => 'forbidden',
            'message' => 'Your Control Panel role cannot create '.$entry->collectionHandle().' entries on the '.$entry->locale().' site. Nothing was saved.',
        ];
    }

    /**
     * Ids of the entries duplicated from this one. The action does not return
     * its copy, so the new id is the one that appears after it runs.
     *
     * @return array<int, string>
     */
    private function copiesOf(Entry $original): array
    {
        return EntryFacade::query()->where('duplicated_from', $original->id())->get()->map(fn ($e) => (string) $e->id())->all();
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
    private function fingerprint(Entry $entry, array $segments): string
    {
        return hash('sha256', (string) json_encode([$entry->slug(), $segments]));
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

    private function composeTokenKey(string $token): string
    {
        return 'statamic-ai-assistant:mcp-compose:'.$token;
    }
}
