<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MotionWork\StatamicAiAssistant\Services\EntryWriter;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\LlmDate;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * Saving a composed entry: new, as an unpublished copy, or in place. The
 * preview maps and validates it as the agent would; the save needs the
 * confirmation_token.
 */
final class ComposeTools
{
    public function __construct(
        private ConnectorContext $context,
    ) {}

    /**
     * Map the composed JSON as the agent would and describe the result:
     * a new entry, an unpublished copy of an existing one, or the existing
     * entry changed in place. Writes nothing.
     *
     * @param  array<string, mixed>  $composed
     * @return array<string, mixed>
     */
    public function previewComposeEntry(array $composed, ?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId, ?string $mode, ?string $parentId): array
    {
        if ($composed === []) {
            return ['saved' => false, 'error' => 'missing_data', 'message' => 'Pass the composed entry as data. Call get_content_model for its format.'];
        }

        $target = $this->composeTarget($collectionHandle, $blueprintHandle, $siteHandle, $entryId, $mode);
        if (isset($target['error'])) {
            return $target;
        }
        ['source' => $source, 'mode' => $mode, 'collection' => $collection, 'blueprint' => $blueprint, 'site' => $site] = $target;

        [$data, $warnings] = $this->composedData($composed, $source, $blueprint, $site->handle());

        // A dated collection keeps the date on the entry, not in its data.
        $date = null;
        if ($collection->dated()) {
            $date = $source?->date() ?? LlmDate::parse($data['date'] ?? null) ?? now();
            unset($data['date']);
        }

        $problems = $this->validationProblems($blueprint, $date ? $data + ['date' => $date->format('Y-m-d H:i')] : $data);
        if ($problems !== []) {
            return [
                'saved' => false,
                'error' => 'invalid_entry',
                'problems' => $problems,
                'warnings' => $warnings,
                'message' => 'The composed entry does not pass the blueprint\'s validation. Fix these fields and preview again. Nothing was saved.',
            ];
        }

        $parent = $mode === 'create' ? $this->composeParent($parentId, $collection->handle(), $site->handle()) : null;
        if (is_array($parent)) {
            return $parent;
        }

        return $this->composeConfirmation($target, $parent, $data, $date, $warnings);
    }

    /**
     * Caches the composed entry under a confirmation token and describes the
     * save for the user.
     *
     * @param  array<string, mixed>  $target  From composeTarget()
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function composeConfirmation(array $target, ?Entry $parent, array $data, ?Carbon $date, array $warnings): array
    {
        ['source' => $source, 'mode' => $mode, 'collection' => $collection, 'blueprint' => $blueprint, 'site' => $site] = $target;

        $title = match (true) {
            is_string($data['title'] ?? null) && $data['title'] !== '' => $data['title'],
            $source !== null => EntryLabel::for($source),
            default => 'Untitled',
        };
        $slug = $mode === 'in_place' ? (string) $source->slug() : $this->context->freeSlug($collection->handle(), $site, $title);

        $token = (string) Str::uuid();
        Cache::put(self::tokenKey($token), [
            'mode' => $mode,
            'user_id' => $this->context->actor() !== null ? (string) $this->context->actor()->id() : null,
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->handle(),
            'site' => $site->handle(),
            'entry_id' => $source ? (string) $source->id() : null,
            'parent_id' => $parent ? (string) $parent->id() : null,
            'slug' => $slug,
            'data' => $data,
            'date' => $date?->format('Y-m-d H:i'),
            'fingerprint' => $source ? hash('sha256', (string) json_encode($source->data()->all())) : null,
        ], now()->addMinutes(ConnectorContext::TOKEN_TTL_MINUTES));

        $what = match ($mode) {
            'in_place' => 'change "'.EntryLabel::for($source).'" in place'.($source->published() ? '; it is published, so the live page changes right away' : ''),
            'copy' => 'save the result as a new unpublished copy of "'.EntryLabel::for($source).'" next to it in the page tree; the original stays as it is',
            default => 'create a new unpublished '.$collection->handle().' entry'.($parent ? ' under "'.EntryLabel::for($parent).'"' : ''),
        };

        return [
            'saved' => false,
            'confirmation_token' => $token,
            'expires_in_minutes' => ConnectorContext::TOKEN_TTL_MINUTES,
            'mode' => $mode,
            'title' => $title,
            'slug' => $slug,
            'site' => $site->handle(),
            'outline' => (new EntryOutline)->outline($blueprint, $data),
            'warnings' => array_values($warnings),
            'message' => 'This will '.$what.' (site '.$site->handle().'). Show the outline and any warnings to the user. Nothing is saved until save_compose_entry is called with this confirmation_token and confirmed true, after they agree.',
        ];
    }

    /**
     * What a compose writes to: an existing entry (in place or as a copy,
     * which the user must have chosen) or a new entry, each checked against
     * the actor's Control Panel permissions. Refusals carry an `error`.
     *
     * @return array<string, mixed>
     */
    private function composeTarget(?string $collectionHandle, ?string $blueprintHandle, ?string $siteHandle, ?string $entryId, ?string $mode): array
    {
        if ($entryId !== null && $entryId !== '') {
            $source = $this->context->findEntry($entryId);
            if (is_array($source)) {
                return $source;
            }
            if (! in_array($mode, ['in_place', 'copy'], true)) {
                return ['saved' => false, 'error' => 'mode_required', 'message' => 'Ask the user whether to change this entry in place or save the result as an unpublished copy, then pass mode "in_place" or "copy".'];
            }
            $denied = $mode === 'in_place' ? $this->context->refuseEdit($source) : $this->context->refuseCreate($source);
            if ($denied !== null) {
                return $denied;
            }

            return ['source' => $source, 'mode' => $mode, 'collection' => $source->collection(), 'blueprint' => $source->blueprint(), 'site' => $source->site()];
        }

        $resolved = $this->context->resolveTarget((string) $collectionHandle, $blueprintHandle, $siteHandle);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        ['collection' => $collection, 'site' => $site] = $resolved;
        if ($this->context->actor() === null) {
            return ['saved' => false, 'error' => 'not_signed_in', 'message' => 'Creating entries needs a signed-in Control Panel account.'];
        }
        if (! $this->context->actor()->can('create', [Entry::class, $collection, $site])) {
            return ['saved' => false, 'error' => 'forbidden', 'message' => 'Your Control Panel role cannot create '.$collection->handle().' entries on the '.$site->handle().' site. Nothing was saved.'];
        }

        return ['source' => null, 'mode' => 'create'] + $resolved;
    }

    /**
     * The composed JSON mapped as the agent maps it; for a refactor merged onto
     * the stored data, keeping blocks sent back by id exactly as stored.
     *
     * @param  array<string, mixed>  $composed
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function composedData(array $composed, ?Entry $source, Blueprint $blueprint, string $siteHandle): array
    {
        $slots = [];
        if ($source !== null) {
            [$composed, $slots] = (new KeptBlocks)->splitKeptBlocks($composed, $source->data()->all());
        }
        $mapped = $this->context->generator()->composer->mapComposedData($composed, $blueprint, $siteHandle, $source !== null);
        $warnings = $mapped['warnings'];
        $data = $source !== null
            ? EntryWriter::merge($source->data()->all(), (new KeptBlocks)->reassembleBlocks($mapped['data'], $slots, $warnings))
            : $mapped['data'];

        return [$data, $warnings];
    }

    /**
     * The parent for a new entry, null for none, or the refusal when it is not
     * an entry of the same collection and site.
     *
     * @return Entry|array<string, mixed>|null
     */
    private function composeParent(?string $parentId, string $collectionHandle, string $siteHandle): Entry|array|null
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = EntryFacade::find($parentId);
        if (! $parent || $parent->collectionHandle() !== $collectionHandle || $parent->locale() !== $siteHandle) {
            return ['saved' => false, 'error' => 'invalid_parent', 'message' => 'The parent must be an entry of the same collection and site. Nothing was saved.'];
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validationProblems(Blueprint $blueprint, array $data): array
    {
        // The Control Panel validates its form values, so validate the
        // preprocessed values rather than the stored ones (dates differ).
        try {
            $blueprint->fields()->addValues($data)->preProcess()->validator()->validate();
        } catch (ValidationException $e) {
            return array_map(fn ($messages) => implode(' ', $messages), $e->errors());
        }

        return [];
    }

    public static function tokenKey(string $token): string
    {
        return 'statamic-ai-assistant:mcp-compose:'.$token;
    }
}
