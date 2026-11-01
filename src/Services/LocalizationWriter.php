<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Mcp\ContentCommitter;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Writes a translated localization, for DeepL translations and for text that
 * Claude translated: finds or makes the target, keeps its slug, and saves
 * quietly with one content commit per top-level translation.
 */
final class LocalizationWriter
{
    /**
     * The localization to write into. Re-checks for one created while we were
     * translating or waiting on the lock (another worker, or a recursive
     * resolution in a parallel job) so it is updated instead of duplicated;
     * then the id the reference resolver reserved; else a new localization.
     */
    public function target(mixed $originEntry, string $targetSite, ?string $blueprintHandle, ?string $reservedId = null): mixed
    {
        $targetEntry = Entry::query()
            ->where('origin', $originEntry->id())
            ->where('site', $targetSite)
            ->first();

        if (! $targetEntry && $reservedId) {
            $targetEntry = Entry::find($reservedId)
                ?? $this->make($originEntry, $targetSite, $blueprintHandle)->id($reservedId);
        }

        return $targetEntry ?? $this->make($originEntry, $targetSite, $blueprintHandle);
    }

    /**
     * From the translated title, else the source entry's slug.
     *
     * @param  array<string, mixed>  $translatedData
     */
    public function slug(array $translatedData, mixed $sourceEntry): string
    {
        $translatedTitle = $translatedData['title'] ?? '';

        return is_string($translatedTitle) && $translatedTitle !== ''
            ? Str::slug($translatedTitle)
            : Str::slug((string) $sourceEntry->slug());
    }

    /**
     * An existing localization keeps its slug, so re-translating never changes
     * a live URL. saveQuietly keeps EntrySaving/EntrySaved listeners (e.g.
     * stillat/relationships syncing bidirectional references) from touching
     * the origin entries.
     *
     * @param  array<string, mixed>  $translatedData
     */
    public function save(mixed $targetEntry, array $translatedData, string $slug, mixed $originEntry): void
    {
        if (! $targetEntry->slug()) {
            $targetEntry->slug($slug);
        }
        $targetEntry->data($translatedData);

        if ($originEntry->published()) {
            $targetEntry->published(true);
        }

        $targetEntry->saveQuietly();
    }

    /**
     * Quiet saves skip Statamic's git listener. One commit at the top of a
     * translation covers the entry and any linked localizations already written.
     */
    public function commit(mixed $originEntry, string $targetSite, int $currentDepth): void
    {
        if ($currentDepth !== 0) {
            return;
        }

        app(ContentCommitter::class)->commit(
            'Translate "'.EntryLabel::for($originEntry).'" into '.$targetSite,
            User::current(),
        );
    }

    private function make(mixed $originEntry, string $targetSite, ?string $blueprintHandle): mixed
    {
        return Entry::make()
            ->collection($originEntry->collectionHandle())
            ->blueprint($blueprintHandle)
            ->locale($targetSite)
            ->origin($originEntry->id());
    }
}
