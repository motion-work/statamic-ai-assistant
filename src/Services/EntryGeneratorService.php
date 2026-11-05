<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

class EntryGeneratorService
{
    private EntryGeneratorAssetResolver $assetResolver;

    private EntryStructureSerializer $structureSerializer;

    private FieldSchemaBuilder $schema;

    private FieldValueMapper $mapper;

    private GenerationPrompt $prompt;

    private UpdateAssetFields $updateAssets;

    private GenerationTargets $targets;

    private GenerationSourceContext $sources;

    private EntryCompletion $completion;

    private EntryResponseSchema $responseSchema;

    /** For the Claude connector, which composes entries itself. */
    public readonly EntryComposer $composer;

    public function __construct(
        AbstractAiService $aiService,
        ?EntryGeneratorAssetResolver $assetResolver = null,
        ?EntryGeneratorLinkFallback $linkFallback = null,
        ?PromptUrlFetcher $promptUrlFetcher = null,
        ?SetHintsService $setHints = null,
        ?FigmaContentFetcher $figma = null,
        ?RemoteImageFetcher $imageFetcher = null,
        ?EditorialGuidanceService $editorialGuidance = null,
    ) {
        $this->assetResolver = $assetResolver ?? new EntryGeneratorAssetResolver;
        $promptUrlFetcher ??= new PromptUrlFetcher;
        $setHints ??= new SetHintsService;
        $this->structureSerializer = new EntryStructureSerializer;
        $search = new EntrySearch;
        $options = new FieldOptionCatalog;
        $parser = new EntryJsonParser($aiService);
        $this->schema = new FieldSchemaBuilder($setHints, $options);
        $this->updateAssets = new UpdateAssetFields($this->assetResolver);
        $this->targets = new GenerationTargets($aiService, $promptUrlFetcher, $figma, $parser);
        $this->prompt = new GenerationPrompt($editorialGuidance ?? app(EditorialGuidanceService::class), $setHints);
        $this->sources = new GenerationSourceContext($promptUrlFetcher, $figma);
        $this->completion = new EntryCompletion(
            $aiService,
            new GenerationToolLoop($aiService, $promptUrlFetcher, $imageFetcher, $this->structureSerializer),
            $parser,
        );
        $this->mapper = new FieldValueMapper($aiService, $this->assetResolver, $linkFallback ?? new EntryGeneratorLinkFallback, $options, $search);
        $this->composer = new EntryComposer($this->schema, $this->prompt, $this->mapper, $this->assetResolver);
        $this->responseSchema = new EntryResponseSchema;
    }

    /**
     * Generate content for an entry from a prompt (does NOT save).
     *
     * @param  callable(string): void|null  $onStreamToken  Optional callback for each streamed assistant text delta (NDJSON / CP drawer)
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug  When set (multi-entry stream), reuses one Jina text fetch and appendix so each entry does not re-fetch URLs.
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    public function generateContent(
        string $collectionHandle,
        string $blueprintHandle,
        string $prompt,
        string $locale,
        ?string $attachmentContent = null,
        ?callable $onStreamToken = null,
        ?array $prefetchedUrlAug = null,
    ): array {
        $blueprint = $this->entryBlueprint($collectionHandle, $blueprintHandle);
        $fieldSchema = $this->schema->buildFieldSchema($blueprint, $locale);
        $systemMessage = $this->prompt->buildSystemMessage($fieldSchema, $locale);

        [$urlAug, $figmaAug, $combinedAppendix] = $this->sources->forNewEntry($prompt, $this->completion->usesUrlTool(), $prefetchedUrlAug);

        $userMessage = $this->prompt->buildUserMessage($prompt, $attachmentContent, $combinedAppendix);

        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $toolWarnings = [];

        // Images the LLM copies from the source via save_remote_image land here;
        // merged into the preferred-asset queue below so the resolver assigns
        // them to this entry's image fields (matched by container).
        $fetchedImages = new PreferredAssetPaths;
        $imageContainerHint = $this->assetResolver->primaryAssetContainer($blueprint);

        // Images generated on request (generate_image) join the same queue.
        $parsedData = ImageGeneration::during($fetchedImages, $imageContainerHint, fn () => $this->completion->entryJson(
            $messages,
            $onStreamToken,
            $toolWarnings,
            $fetchedImages,
            $imageContainerHint,
            (string) $attachmentContent !== '',
            $this->responseSchema->responseFormat($fieldSchema),
        ));

        // Merge any preferred assets surfaced by the URL augmentation step
        // (single-prompt path) with the explicit ones passed in by the caller.
        // Either or both can be empty.
        $aggregatedPreferred = PreferredAssetPaths::merge(
            $fetchedImages,
            $urlAug['preferred'] ?? new PreferredAssetPaths,
        );

        $result = $this->mapper->mapToFieldData($parsedData, $blueprint, $locale, $prompt, $aggregatedPreferred);

        array_push(
            $result['warnings'],
            ...($prefetchedUrlAug === null ? $urlAug['warnings'] : []),
            ...$figmaAug['warnings'],
            ...$toolWarnings,
        );

        return $result;
    }

    /**
     * The collection's visible blueprint with this handle, else its first one.
     */
    private function entryBlueprint(string $collectionHandle, string $blueprintHandle): Blueprint
    {
        $collection = Collection::findByHandle($collectionHandle);

        if (! $collection) {
            throw new \RuntimeException(__('Collection not found.'));
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $blueprintHandle
            ? $visible->keyBy->handle()->get($blueprintHandle)
            : null;

        $blueprint ??= $visible->first();

        if (! $blueprint) {
            throw new \RuntimeException(__('Blueprint not found.'));
        }

        return $blueprint;
    }

    /** @see GenerationTargets::getCollectionsCatalog() */
    public function getCollectionsCatalog(int $entriesPerCollection = 0): array
    {
        return $this->targets->getCollectionsCatalog($entriesPerCollection);
    }

    /** @see GenerationTargets::resolveTargetFromPrompt() */
    public function resolveTargetFromPrompt(string $prompt, ?string $attachmentContent = null, ?array $prefetchedUrlAug = null, ?array $prefetchedFigmaAug = null): array
    {
        return $this->targets->resolveTargetFromPrompt($prompt, $attachmentContent, $prefetchedUrlAug, $prefetchedFigmaAug);
    }

    /** @see FieldSchemaBuilder::getFieldSchemaForPreview() */
    public function getFieldSchemaForPreview(Blueprint $blueprint): array
    {
        return $this->schema->getFieldSchemaForPreview($blueprint);
    }

    // ---------------------------------------------------------------------
    //  Update path (AI agent update_entry_job tool).
    //  Kept structurally separate from the create path so it can be removed,
    //  swapped, or replaced without touching generateContent / EntryWriter::create().
    // ---------------------------------------------------------------------

    /**
     * Generate a partial-update payload for an existing entry. Returns only the
     * fields the LLM chose to change. The system prompt embeds the current
     * values so the model can decide what to keep, what to rewrite, and what to leave alone.
     *
     * @param  callable(string): void|null  $onStreamToken
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[], collection: string, blueprint: string}
     */
    public function generateUpdateForEntry(
        string $entryId,
        string $prompt,
        string $locale,
        ?string $attachmentContent = null,
        ?callable $onStreamToken = null,
        ?array $prefetchedUrlAug = null,
    ): array {
        [$entry, $blueprint] = $this->entryWithBlueprint($entryId);

        $fieldSchema = $this->schema->buildFieldSchema($blueprint, $locale);
        // Update mode: surface asset fields the create path hides (`FieldTypes::SKIP_TYPES`)
        // so the LLM can ask for a replacement. We walk into group fields too — many
        // blueprints place their hero/main image inside a group like `image_or_video`.
        // Returns a list of {field, path} pairs the resolver step uses below.
        $assetFields = $this->updateAssets->augmentSchemaWithAssetFields($fieldSchema, $blueprint);

        [$messages, $urlWarnings, $snapshotIncompleteFields] = $this->updateMessages($entry, $blueprint, $fieldSchema, $locale, $prompt, $attachmentContent, $prefetchedUrlAug);

        $toolWarnings = [];
        // Images generated on request: the model sets the asset field to the
        // returned path, and the replacement step below takes them from here.
        $generated = new PreferredAssetPaths;
        // No image container for save_remote_image: on the update path saved images would never be attached.
        $parsedData = ImageGeneration::during($generated, $this->assetResolver->primaryAssetContainer($blueprint), fn () => $this->completion->entryJson(
            $messages,
            $onStreamToken,
            $toolWarnings,
            new PreferredAssetPaths,
            null,
            (string) $attachmentContent !== '',
            // Update mode: only the keys the model changes, so nothing is required.
            $this->responseSchema->responseFormat($fieldSchema, update: true),
        ));
        $result = $this->mapper->mapToFieldData($parsedData, $blueprint, $locale, $prompt, null, true);

        // Resolve asset fields (top-level + nested in groups) the LLM asked to
        // replace. Fields the LLM did NOT mention stay absent from $data, so
        // the merge in EntryWriter::update() preserves the existing image.
        $this->updateAssets->resolveUpdateAssetReplacements($result, $entry, $assetFields, $parsedData, $generated);
        $this->warnAboutIncompleteReplacements($result, $snapshotIncompleteFields);

        array_push($result['warnings'], ...$urlWarnings, ...$toolWarnings);

        $result['collection'] = (string) $entry->collectionHandle();
        $result['blueprint'] = (string) $blueprint->handle();

        return $result;
    }

    /**
     * The entry to update and its blueprint.
     *
     * @return array{0: EntryContract, 1: Blueprint}
     */
    private function entryWithBlueprint(string $entryId): array
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new \RuntimeException(__('Entry not found.'));
        }

        $blueprint = $entry->blueprint();

        if (! $blueprint) {
            throw new \RuntimeException(__('Blueprint not found for entry.'));
        }

        return [$entry, $blueprint];
    }

    /**
     * The update prompt: the system message with the schema and the entry's
     * current values (static part first, the snapshot last), and the user
     * message with its sources. Also returns the URL warnings and the fields too
     * large to show the model in full.
     *
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @param  array<string, mixed>|null  $prefetchedUrlAug
     * @return array{0: array<int, array{role: string, content: string}>, 1: array<int, string>, 2: array<int, string>}
     */
    private function updateMessages(EntryContract $entry, Blueprint $blueprint, array $fieldSchema, string $locale, string $prompt, ?string $attachmentContent, ?array $prefetchedUrlAug): array
    {
        // Current values as text, complex fields as raw JSON so the model can
        // return a full replacement; size-capped, so oversized fields are listed.
        $snapshotIncompleteFields = [];
        $currentSnapshot = $this->structureSerializer->serialize($entry, $blueprint, $snapshotIncompleteFields);

        [$urlWarnings, $combinedAppendix] = $this->sources->forUpdate($prompt, $this->completion->usesUrlTool(), $prefetchedUrlAug);

        return [[
            ['role' => 'system', 'content' => $this->prompt->buildUpdateSystemMessage($fieldSchema, $locale, $currentSnapshot)],
            ['role' => 'user', 'content' => $this->prompt->buildUserMessage($prompt, $attachmentContent, $combinedAppendix)],
        ], $urlWarnings, $snapshotIncompleteFields];
    }

    /**
     * Data-loss guard: list fields (replicator/grid) are replaced wholesale on
     * update. When the snapshot had to truncate or omit such a field, the LLM
     * never saw its full content — a returned replacement may silently drop
     * blocks. Surface that as a review warning instead of failing the update.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $snapshotIncompleteFields
     */
    private function warnAboutIncompleteReplacements(array &$result, array $snapshotIncompleteFields): void
    {
        foreach (array_intersect($snapshotIncompleteFields, array_keys($result['data'] ?? [])) as $incompleteHandle) {
            $result['warnings'][] = __(
                'The field ":field" was too large to show the AI in full, but the AI returned a replacement for it. Review that field carefully before publishing — blocks the AI never saw may be missing.',
                ['field' => $incompleteHandle]
            );
        }
    }
}
