<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\EntryTreePlacement;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Structures\Tree;

/**
 * Moves an entry within its structured collection's tree: a new parent (or the
 * top level) and optionally a position after a sibling. This is what "move
 * this FAQ into that group" means in Statamic; the parent is not an entry
 * field, so an update job cannot do it.
 *
 * Every refusal comes back as ['ok' => false, 'error', 'message'] in English
 * for the model, so it can correct the call or tell the editor.
 */
final class EntryMover
{
    /**
     * @return array<string, mixed>
     */
    public function move(string $entryId, ?string $parentId, ?string $afterId, ?UserContract $user): array
    {
        $entry = Entry::find($entryId);
        if (! $entry) {
            return $this->refuse('entry_not_found', 'No entry with this id exists.');
        }

        $collection = $entry->collection();
        if (! $collection?->hasStructure()) {
            return $this->refuse('not_structured', "The collection \"{$entry->collectionHandle()}\" has no tree, so its entries cannot be moved under a parent.");
        }

        if (! $this->mayMove($user, $entry)) {
            return $this->refuse('not_allowed', 'The editor may not reorder this collection or edit this entry.');
        }

        $tree = $collection->structure()->in($entry->locale());
        $parent = null;
        if ($parentId !== null) {
            $parent = Entry::find($parentId);
            $problem = $this->parentProblem($tree, $entry, $parent);
            if ($problem !== null) {
                return $problem;
            }
        }

        $problem = $this->rootProblem($tree, $entryId, $parentId)
            ?? $this->siblingProblem($tree, $entryId, $parentId, $afterId)
            ?? $this->depthProblem($tree, $entryId, $parentId);
        if ($problem !== null) {
            return $problem;
        }

        EntryTreePlacement::place($tree, $entryId, $parentId, $afterId);

        return [
            'ok' => true,
            'entry_id' => $entryId,
            'parent_id' => $parentId,
            'label' => $this->label($entry, $parent),
        ];
    }

    private function mayMove(?UserContract $user, EntryContract $entry): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isSuper()) {
            return true;
        }

        return $user->can('edit', $entry) && $user->can('reorder', $entry->collection());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parentProblem(Tree $tree, EntryContract $entry, ?EntryContract $parent): ?array
    {
        if (! $parent) {
            return $this->refuse('parent_not_found', 'No entry with the given parent_id exists.');
        }
        if ($parent->collectionHandle() !== $entry->collectionHandle() || $parent->locale() !== $entry->locale()) {
            return $this->refuse('parent_elsewhere', 'The parent must be an entry of the same collection and site.');
        }
        if ($parent->id() === $entry->id() || $this->isInsideBranch($tree, (string) $entry->id(), (string) $parent->id())) {
            return $this->refuse('parent_is_descendant', 'An entry cannot be moved under itself or under one of its own children.');
        }
        if (EntryTreePlacement::depthOf($tree->tree(), (string) $parent->id()) === null) {
            return $this->refuse('parent_not_in_tree', 'The parent entry is not part of this collection\'s tree yet.');
        }

        return null;
    }

    private function isInsideBranch(Tree $tree, string $entryId, string $candidateId): bool
    {
        [$branch] = EntryTreePlacement::remove($tree->tree(), $entryId);

        return $branch !== null && EntryTreePlacement::depthOf($branch['children'] ?? [], $candidateId) !== null;
    }

    /**
     * With `root: true`, the first top-level page is the home page: it stays
     * first and has no children.
     *
     * @return array<string, mixed>|null
     */
    private function rootProblem(Tree $tree, string $entryId, ?string $parentId): ?array
    {
        if (! $tree->structure()->expectsRoot()) {
            return null;
        }
        $rootId = (string) ($tree->tree()[0]['entry'] ?? '');
        if ($rootId === $entryId) {
            return $this->refuse('root_fixed', 'This entry is the root page of the tree and cannot be moved.');
        }
        if ($rootId !== '' && $rootId === $parentId) {
            return $this->refuse('root_no_children', 'The root page of this tree cannot have children.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function siblingProblem(Tree $tree, string $entryId, ?string $parentId, ?string $afterId): ?array
    {
        if ($afterId === null) {
            return null;
        }
        $parents = EntryTreePlacement::parentMap($tree->tree());
        if ($afterId === $entryId || ! array_key_exists($afterId, $parents) || $parents[$afterId] !== $parentId) {
            return $this->refuse('after_not_sibling', 'after_id must be another child of the chosen parent (or a top-level entry when moving to the top level).');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function depthProblem(Tree $tree, string $entryId, ?string $parentId): ?array
    {
        $maxDepth = (int) $tree->structure()->maxDepth();
        if ($maxDepth <= 0) {
            return null;
        }
        [$branch] = EntryTreePlacement::remove($tree->tree(), $entryId);
        $parentDepth = $parentId === null ? 0 : (int) EntryTreePlacement::depthOf($tree->tree(), $parentId);
        $depth = $parentDepth + EntryTreePlacement::heightOf($branch ?? ['entry' => $entryId]);

        return $depth > $maxDepth
            ? $this->refuse('too_deep', "This tree allows at most {$maxDepth} levels; the move would need {$depth}.")
            : null;
    }

    private function label(EntryContract $entry, ?EntryContract $parent): string
    {
        $title = (string) ($entry->value('title') ?? $entry->id());

        return $parent
            ? (string) __('“:title” moved under “:parent”.', ['title' => $title, 'parent' => (string) ($parent->value('title') ?? $parent->id())])
            : (string) __('“:title” moved to the top level.', ['title' => $title]);
    }

    /**
     * @return array{ok: false, error: string, message: string}
     */
    private function refuse(string $error, string $message): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message];
    }
}
