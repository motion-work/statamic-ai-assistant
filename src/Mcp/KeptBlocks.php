<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Statamic\Contracts\Entries\Entry;

/**
 * Refactors keep stored blocks: the agent's mapper does not round-trip a stored
 * block (it drops asset paths and entry references and re-enables disabled
 * sets), so a block Claude sends back with its stored id is kept exactly as
 * stored and only the keys Claude changed are mapped. Blocks without an id
 * are new and mapped whole.
 */
final class KeptBlocks
{
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
    public function splitKeptBlocks(array $composed, array $existing): array
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
    public function reassembleBlocks(array $mapped, array $slots, array &$warnings): array
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
                    $block = $this->keptBlock($slot, $mappedList, $warnings);
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

    /**
     * A stored block sent back by id: exactly as stored, with only the keys
     * Claude changed taken from the mapped copy.
     *
     * @param  array<string, mixed>  $slot
     * @param  array<int, array<string, mixed>>  $mappedList
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function keptBlock(array $slot, array $mappedList, array &$warnings): array
    {
        $block = $slot['stored'];
        $fresh = $slot['mapped'] !== null ? ($mappedList[$slot['mapped']] ?? []) : [];

        foreach ($slot['changed'] as $key) {
            if (array_key_exists($key, $fresh)) {
                $block[$key] = $fresh[$key];
            } else {
                $warnings[] = 'The new value for "'.$key.'" in the '.$block['type'].' block could not be used; the stored value was kept.';
            }
        }

        return $block;
    }
}
