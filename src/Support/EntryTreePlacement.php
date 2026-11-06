<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Structures\Tree;

/**
 * Where entries sit in a structured collection's tree, read and changed on the
 * tree's raw branches ([{entry, children?}, …]). Shared by the agent's
 * move_entry tool and the Claude connector's compose save, so both place pages
 * the same way. Statamic's own Tree::move() cannot place a page after a given
 * sibling, hence the branch helpers.
 */
final class EntryTreePlacement
{
    /**
     * Put $entryId under $parentId (null: top level), after the sibling
     * $afterId (null: at the end), keeping its own children, and save the tree.
     */
    public static function place(Tree $tree, string $entryId, ?string $parentId, ?string $afterId = null): void
    {
        [$node, $branches] = self::remove($tree->tree(), $entryId);
        $node ??= ['entry' => $entryId];

        if ($parentId !== null && self::depthOf($branches, $parentId) === null) {
            // Same contract as Statamic's Tree::appendTo(): never drop the page.
            throw new \RuntimeException("Page [{$parentId}] does not exist in this structure");
        }

        $tree->tree(self::insert($branches, $node, $parentId, $afterId));
        $tree->save();
    }

    /**
     * The branch of $entryId (with its children) and the branches without it.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array{0: ?array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    public static function remove(array $branches, string $entryId): array
    {
        $match = null;
        $kept = [];

        foreach ($branches as $branch) {
            if ((string) ($branch['entry'] ?? '') === $entryId) {
                $match = $branch;

                continue;
            }

            [$found, $children] = self::remove($branch['children'] ?? [], $entryId);
            $match ??= $found;
            $branch['children'] = $children;
            if ($children === []) {
                unset($branch['children']);
            }
            $kept[] = $branch;
        }

        return [$match, $kept];
    }

    /**
     * $branches with $node inserted under $parentId (null: top level), right
     * after $afterId when that is one of the parent's children, else last.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    public static function insert(array $branches, array $node, ?string $parentId, ?string $afterId = null): array
    {
        if ($parentId === null) {
            return self::insertAmong($branches, $node, $afterId);
        }

        foreach ($branches as $i => $branch) {
            if ((string) ($branch['entry'] ?? '') === $parentId) {
                $branches[$i]['children'] = self::insertAmong($branch['children'] ?? [], $node, $afterId);

                return $branches;
            }
            if (! empty($branch['children'])) {
                $branches[$i]['children'] = self::insert($branch['children'], $node, $parentId, $afterId);
            }
        }

        return $branches;
    }

    /**
     * Depth of $entryId (top level = 1), or null when it is not in the tree.
     *
     * @param  array<int, array<string, mixed>>  $branches
     */
    public static function depthOf(array $branches, string $entryId, int $level = 1): ?int
    {
        foreach ($branches as $branch) {
            if ((string) ($branch['entry'] ?? '') === $entryId) {
                return $level;
            }
            $depth = self::depthOf($branch['children'] ?? [], $entryId, $level + 1);
            if ($depth !== null) {
                return $depth;
            }
        }

        return null;
    }

    /**
     * Levels a branch spans: 1 for a page without children.
     *
     * @param  array<string, mixed>  $node
     */
    public static function heightOf(array $node): int
    {
        $children = $node['children'] ?? [];
        if ($children === []) {
            return 1;
        }

        return 1 + max(array_map(fn (array $child) => self::heightOf($child), $children));
    }

    /**
     * Each entry's parent entry id (null at the top level).
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<string, ?string>
     */
    public static function parentMap(array $branches, ?string $parentId = null): array
    {
        $map = [];
        foreach ($branches as $branch) {
            $id = (string) ($branch['entry'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = $parentId;
            $map += self::parentMap($branch['children'] ?? [], $id);
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $siblings
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    private static function insertAmong(array $siblings, array $node, ?string $afterId): array
    {
        $siblings = array_values($siblings);
        foreach ($siblings as $i => $sibling) {
            if ($afterId !== null && (string) ($sibling['entry'] ?? '') === $afterId) {
                array_splice($siblings, $i + 1, 0, [$node]);

                return $siblings;
            }
        }
        $siblings[] = $node;

        return $siblings;
    }
}
