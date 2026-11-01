<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * What every connector tool shares: the signed-in account (also the request's
 * logged-in user, so policies, updated_by, git commits and Statamic's own
 * actions see it), the checks of its Control Panel permissions, and the
 * lookups the tools have in common. One per request.
 */
final class ConnectorContext
{
    /** Minutes a preview's confirmation_token stays valid. */
    public const TOKEN_TTL_MINUTES = 30;

    private ?UserContract $actor = null;

    public function setActor(?UserContract $user): void
    {
        $this->actor = $user;
        if ($user !== null) {
            auth()->setUser($user);
        }
    }

    public function actor(): ?UserContract
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>|Entry
     */
    public function findEntry(string $entryId): array|Entry
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
     * In-place edits always need an account, so updated_by and the commit
     * name a person.
     *
     * @return array<string, mixed>|null
     */
    public function refuseEdit(Entry $entry): ?array
    {
        if ($this->actor === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Editing needs a signed-in Control Panel account.'];
        }

        return $this->refuse('edit', $entry);
    }

    public function generator(): EntryGeneratorService
    {
        return app(EntryGeneratorService::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTarget(string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle): array
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

    public function freeSlug(string $collectionHandle, \Statamic\Sites\Site $site, string $title): string
    {
        $base = Str::slug($title, '-', $site->lang()) ?: 'entry';
        $slug = $base;
        for ($n = 2; EntryFacade::query()->where('collection', $collectionHandle)->where('site', $site->handle())->where('slug', $slug)->count() > 0; $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    public function parentInTree(Entry $entry): ?string
    {
        $page = $entry->structure()?->in($entry->locale())->find($entry->id());
        $parent = $page?->parent();
        if (! $parent || ($entry->structure()->expectsRoot() && $entry->structure()->in($entry->locale())->root()['entry'] === $parent->id())) {
            return null;
        }

        return (string) $parent->id();
    }

    /**
     * Statamic's Duplicate check: the role may create entries in this
     * collection on this site.
     *
     * @return array<string, mixed>|null
     */
    public function refuseCreate(Entry $entry): ?array
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
     * Statamic's entry policy, as in the Control Panel: the collection
     * permission, access to the entry's site, and other authors' entries.
     *
     * @return array<string, mixed>|null
     */
    public function refuse(string $ability, Entry $entry): ?array
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
    public function fingerprint(Entry $entry, array $segments): string
    {
        return hash('sha256', (string) json_encode([$entry->slug(), $segments]));
    }

    /**
     * @return array<string, mixed>
     */
    public function entrySummary(Entry $entry): array
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
}
