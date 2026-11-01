<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * Editing an entry in place: texts, values and the slug, previewed and then
 * saved on confirmation.
 */
final class EntryEditTools
{
    public function __construct(
        private ConnectorContext $context,
        private EntryTranslator $translator,
    ) {}

    /**
     * Without edits or a slug, list the entry's editable segments. With them,
     * describe the change and return a confirmation_token. Writes nothing.
     *
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function previewEntryEdit(string $entryId, array $edits, ?string $slug = null): array
    {
        $entry = $this->context->findEntry($entryId);
        if (is_array($entry)) {
            return $entry;
        }
        if (($denied = $this->context->refuseEdit($entry)) !== null) {
            return $denied;
        }

        $segments = $this->translator->editSegments->editableSegments($entry);
        if ($edits === [] && ($slug === null || trim($slug) === '')) {
            return array_merge($this->context->entrySummary($entry), [
                'saved' => false,
                'segments' => $segments,
                'message' => 'Call preview_entry_edit again with edits (a map of segment id to the new text) and/or a new slug. Only what you pass changes. HTML segments must stay HTML.',
            ]);
        }

        [$changes, $unknown, $invalid] = $this->diffEdits($segments, $edits);

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

        $newSlug = $this->requestedSlug($entry, $slug);
        if (is_array($newSlug)) {
            return $newSlug;
        }

        if ($changes === [] && $newSlug === null) {
            return ['saved' => false, 'error' => 'no_changes', 'message' => 'The edits match the current entry. Nothing would change.'];
        }

        return $this->editConfirmation($entry, $segments, $changes, $newSlug);
    }

    /**
     * The edits that change something, keyed by segment id, plus the ids that
     * do not exist and the values a field does not accept.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<string, mixed>  $edits
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>, 2: array<string, string>}
     */
    private function diffEdits(array $segments, array $edits): array
    {
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
                $checked = (new SegmentValueCheck)->checkValue($segment, $new);
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

        return [$changes, $unknown, $invalid];
    }

    /**
     * The new slug, null when none was asked for or it equals the current
     * one, or the refusal for a slug that cannot be used.
     *
     * @return string|array<string, mixed>|null
     */
    private function requestedSlug(Entry $entry, ?string $slug): string|array|null
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }

        $newSlug = $this->normalizeSlug($entry, $slug);

        if (is_string($newSlug) && $newSlug === (string) $entry->slug()) {
            return null;
        }

        return $newSlug;
    }

    /**
     * Caches the edit under a confirmation token and describes it for the user.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<string, array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    private function editConfirmation(Entry $entry, array $segments, array $changes, ?string $newSlug): array
    {
        $token = (string) Str::uuid();
        Cache::put($this->editTokenKey($token), [
            'entry_id' => (string) $entry->id(),
            'user_id' => (string) $this->context->actor()->id(),
            'edits' => array_map(fn ($change) => $change['after'], $changes),
            'slug' => $newSlug,
            'fingerprint' => $this->context->fingerprint($entry, $segments),
        ], now()->addMinutes(ConnectorContext::TOKEN_TTL_MINUTES));

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
            'expires_in_minutes' => ConnectorContext::TOKEN_TTL_MINUTES,
            'entry' => $this->context->entrySummary($entry),
            'changes' => array_values($changes),
            'slug' => $newSlug !== null ? ['before' => (string) $entry->slug(), 'after' => $newSlug] : null,
            'message' => 'This will change '.implode(' and ', $parts).' of "'.EntryLabel::for($entry).'" (site '.$entry->locale().'). '
                .$address
                .'Everything else stays as it is, including the published state. '
                .'Show the changes to the user. Nothing is saved until save_entry_edit is called with this confirmation_token and confirmed true, after they agree.',
        ];
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
        if (! is_array($plan) || $this->context->actor() === null || $plan['user_id'] !== (string) $this->context->actor()->id()) {
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
        if (($denied = $this->context->refuseEdit($entry)) !== null) {
            return $denied;
        }
        if ($this->context->fingerprint($entry, $this->translator->editSegments->editableSegments($entry)) !== $plan['fingerprint']) {
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

        $this->translator->editSegments->applySegmentEdits($entry, $plan['edits']);
        $entry->updateLastModified($this->context->actor());
        $entry->save();
        Cache::forget($this->editTokenKey($token));

        return array_merge($this->context->entrySummary($entry), [
            'saved' => true,
            'changed_segments' => count($plan['edits']),
            'slug_changed' => $plan['slug'] !== null,
        ]);
    }

    private function editTokenKey(string $token): string
    {
        return 'statamic-ai-assistant:mcp-edit:'.$token;
    }
}
