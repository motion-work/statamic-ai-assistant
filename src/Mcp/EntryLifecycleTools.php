<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Actions\DuplicateEntry;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Duplicating an entry (Statamic's own Duplicate action) and publishing or
 * unpublishing it, each described first and done on confirmation.
 */
final class EntryLifecycleTools
{
    public function __construct(
        private ConnectorContext $context,
    ) {}

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
        $entry = $this->context->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if (($denied = $this->context->refuseCreate($entry)) !== null) {
            return $denied;
        }

        $root = $entry->hasOrigin() && $entry->root() ? $entry->root() : $entry;
        if (! $confirmed) {
            $localizations = $root->descendants()->count();

            return [
                'saved' => false,
                'entry' => $this->context->entrySummary($root),
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

        return array_merge($this->context->entrySummary($copy), [
            'saved' => true,
            'duplicated_from' => (string) $root->id(),
            'localizations' => $localizations->map(fn ($e) => $this->context->entrySummary($e))->values()->all(),
            'message' => 'The copy is unpublished. Use preview_entry_edit to change its text, and set_published to publish it.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPublished(string $entryId, bool $published, bool $confirmed): array
    {
        $entry = $this->context->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if ($this->context->actor() === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Publishing needs a signed-in Control Panel account.'];
        }
        if (($denied = $this->context->refuse('publish', $entry)) !== null) {
            return $denied;
        }

        $verb = $published ? 'publish' : 'unpublish';
        if ((bool) $entry->published() === $published) {
            return array_merge($this->context->entrySummary($entry), [
                'saved' => false,
                'message' => 'The entry is already '.($published ? 'published' : 'unpublished').'. Nothing would change.',
            ]);
        }
        if (! $confirmed) {
            return array_merge($this->context->entrySummary($entry), [
                'saved' => false,
                'message' => 'This will '.$verb.' "'.EntryLabel::for($entry).'" (site '.$entry->locale().') only'
                    .($published ? ', so it shows on the website' : ', so it no longer shows on the website')
                    .'. Other languages keep their own state. Show this to the user and call set_published again with confirmed true only after they agree.',
            ]);
        }

        $entry->published($published);
        $entry->updateLastModified($this->context->actor());
        $entry->save();

        return array_merge($this->context->entrySummary($entry), ['saved' => true]);
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
}
