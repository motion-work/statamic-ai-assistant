<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\EntryMover;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * move_entry: put an existing entry under another parent (or at the top level)
 * in its structured collection's tree. Runs inside the planner turn, because
 * it is a small direct write, not content generation. Offered only when the
 * site has a structured collection (see isUseful()).
 */
final class MoveEntryTool implements ChatTool
{
    /**
     * @param  \Closure(): ?string  $userId  The CP user the run acts for.
     */
    public function __construct(
        private EntryMover $mover,
        private \Closure $userId,
    ) {}

    /** Whether any collection keeps its entries in a tree. */
    public static function isUseful(): bool
    {
        return Collection::all()->contains(fn ($collection) => $collection->hasStructure());
    }

    public function name(): string
    {
        return 'move_entry';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'move_entry',
                'description' => 'Move an EXISTING entry to another place in its collection\'s page tree: under a new parent (a group, a section page) or to the top level, optionally right after a given sibling. Only for collections marked `structured` in the catalog. Use this — never update_entry_job — when the user wants to move, nest, group, re-parent or reorder an entry; the parent is not an entry field. Applies immediately.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => ['type' => 'string', 'description' => 'Id of the entry to move.'],
                        'parent_id' => ['type' => 'string', 'description' => 'Id of the new parent entry (same collection and site). Empty string = top level.'],
                        'after_id' => ['type' => 'string', 'description' => 'Optional: id of a sibling under the new parent to place it right after. Omit to place it last.'],
                    ],
                    'required' => ['entry_id', 'parent_id'],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = ToolArguments::decode($argumentsJson, 'move_entry');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $entryId = $this->idArg($args, 'entry_id');
        if ($entryId === null) {
            return ['ok' => false, 'error' => 'entry_id_required', 'message' => 'Pass the id of the entry to move.'];
        }

        $context->reportActivity((string) __('Moving entry :id', ['id' => Str::limit($entryId, 12)]));

        $userId = ($this->userId)();

        return $this->mover->move(
            $entryId,
            $this->idArg($args, 'parent_id'),
            $this->idArg($args, 'after_id'),
            $userId !== null ? User::find($userId) : null,
        );
    }

    public function maxCalls(): ?int
    {
        return 20;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function idArg(array $args, string $key): ?string
    {
        $value = isset($args[$key]) && is_string($args[$key]) ? trim($args[$key]) : '';

        return $value !== '' ? $value : null;
    }
}
