<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Support\EntryTreePlacement;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * The confirmed save of a composed entry: in place, or as a new unpublished
 * entry placed in the page tree.
 */
final class ComposeSaver
{
    public function __construct(private ConnectorContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function saveComposeEntry(string $token, bool $confirmed): array
    {
        if (! $confirmed) {
            return ['saved' => false, 'error' => 'not_confirmed', 'message' => 'Nothing was saved. Show the outline to the user and call save_compose_entry again with confirmed true only after they agree.'];
        }

        $plan = Cache::get(ComposeTools::tokenKey($token));
        if (! is_array($plan) || $plan['user_id'] !== ($this->context->actor() !== null ? (string) $this->context->actor()->id() : null)) {
            return ['saved' => false, 'error' => 'preview_required', 'message' => 'Call preview_compose_entry first and reuse its confirmation_token. Nothing was saved.'];
        }

        $collection = Collection::findByHandle($plan['collection']);
        $site = Site::get($plan['site']);
        $source = $plan['entry_id'] ? EntryFacade::find($plan['entry_id']) : null;
        if (! $collection || ! $site || ($plan['entry_id'] && ! $source)) {
            return ['saved' => false, 'error' => 'missing_entry', 'message' => 'The collection or entry from the preview no longer exists. Nothing was saved.'];
        }

        if ($plan['mode'] === 'in_place') {
            return $this->saveComposedInPlace($source, $plan, $token);
        }

        if (! $this->context->actor() || ! $this->context->actor()->can('create', [Entry::class, $collection, $site])) {
            return ['saved' => false, 'error' => 'forbidden', 'message' => 'Your Control Panel role cannot create '.$collection->handle().' entries on the '.$site->handle().' site. Nothing was saved.'];
        }

        $entry = $this->createComposedEntry($plan, $collection, $site, $source);
        Cache::forget(ComposeTools::tokenKey($token));

        return array_merge($this->context->entrySummary($entry), [
            'saved' => true,
            'mode' => $plan['mode'],
            'message' => 'Saved unpublished. Review it in the Control Panel (edit_url), then publish it with set_published when the user agrees.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function saveComposedInPlace(Entry $source, array $plan, string $token): array
    {
        if (($denied = $this->context->refuseEdit($source)) !== null) {
            return $denied;
        }
        if (hash('sha256', (string) json_encode($source->data()->all())) !== $plan['fingerprint']) {
            Cache::forget(ComposeTools::tokenKey($token));

            return ['saved' => false, 'error' => 'entry_changed', 'message' => 'Someone changed this entry after the preview. Read it again and compose anew. Nothing was saved.'];
        }
        $source->data($plan['data']);
        $source->updateLastModified($this->context->actor());
        $source->save();
        Cache::forget(ComposeTools::tokenKey($token));

        return array_merge($this->context->entrySummary($source), ['saved' => true, 'mode' => 'in_place']);
    }

    /**
     * Saves the composed entry unpublished, with a free slug and, in a
     * structured collection, under its parent or next to the original.
     *
     * @param  array<string, mixed>  $plan
     */
    private function createComposedEntry(array $plan, \Statamic\Contracts\Entries\Collection $collection, \Statamic\Sites\Site $site, ?Entry $source): Entry
    {
        $entry = EntryFacade::make()
            ->collection($collection)
            ->blueprint($plan['blueprint'])
            ->locale($site->handle())
            ->published(false)
            ->data($plan['data']);
        if ($collection->requiresSlugs()) {
            $entry->slug(EntryFacade::query()->where('collection', $collection->handle())->where('site', $site->handle())->where('slug', $plan['slug'])->count() > 0
                ? $this->context->freeSlug($collection->handle(), $site, $plan['slug'])
                : $plan['slug']);
        }
        if ($collection->dated()) {
            $entry->date($plan['date'] ? Carbon::parse($plan['date']) : now());
        }
        $entry->updateLastModified($this->context->actor());
        $entry->save();

        if ($collection->hasStructure()) {
            $tree = $collection->structure()->in($site->handle());
            $parentId = $plan['mode'] === 'copy' ? $this->context->parentInTree($source) : $plan['parent_id'];
            EntryTreePlacement::place($tree, (string) $entry->id(), $parentId ?: null);
        }

        return $entry;
    }
}
