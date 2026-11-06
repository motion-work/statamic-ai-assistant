<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Saves a NEW entry the agent generated as an unpublished draft straight away
 * (config `auto_save_new_entries`), inside the queued job, for the CP user who
 * sent the message and only when Statamic lets them create entries in that
 * collection and site. Updates and localizations never come through here:
 * they overwrite content, so they keep the preview and the editor's confirm.
 *
 * Uses the same EntryWriter::create() as the card's "Save as draft", so slug,
 * blueprint, date and published=false are handled the same way.
 */
class AutoDraftSaver
{
    public function __construct(private EntryWriter $writer) {}

    /**
     * Whether a plan row is a new entry this feature may save.
     *
     * @param  array<string, mixed>  $row
     */
    public static function appliesTo(array $row): bool
    {
        if (! config('statamic-ai-assistant.auto_save_new_entries', true)) {
            return false;
        }

        $operation = (string) ($row['operation'] ?? 'create');
        $existing = is_string($row['entry_id'] ?? null) && $row['entry_id'] !== '';

        return $operation === 'create' && ! $existing && (string) ($row['collection'] ?? '') !== '';
    }

    /**
     * Save the generated entry. Returns the saved entry ({entry_id, edit_url,
     * title}), or a short editor hint ({hint}) when it has to stay a preview.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $data
     * @return array{entry_id?: string, edit_url?: ?string, title?: string, hint?: string}
     */
    public function save(array $row, array $data, ?string $userId, string $site): array
    {
        $collection = Collection::findByHandle((string) $row['collection']);

        if (! $this->userMayCreate($userId, $collection, $site)) {
            return ['hint' => (string) __('Not saved automatically: you may not create entries in :collection. Save the draft below if you want to keep it.', [
                'collection' => $collection?->title() ?? (string) $row['collection'],
            ])];
        }

        try {
            $entry = $this->writer->create((string) $row['collection'], (string) ($row['blueprint'] ?? ''), $site, $data);
        } catch (\Throwable $e) {
            Log::warning('AI agent: saving the new entry as a draft failed', [
                'collection' => $row['collection'],
                'error' => $e->getMessage(),
            ]);

            return ['hint' => (string) __('The draft could not be saved automatically. Save it below.')];
        }

        return [
            'entry_id' => (string) $entry->id(),
            'edit_url' => $entry->editUrl(),
            'title' => (string) ($entry->value('title') ?? ''),
        ];
    }

    private function userMayCreate(?string $userId, mixed $collection, string $site): bool
    {
        $user = $userId !== null && $userId !== '' ? User::find($userId) : null;

        if ($user === null || $collection === null) {
            return false;
        }

        return $user->can('create', [EntryContract::class, $collection, Site::get($site)]);
    }
}
