<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\Concerns\TranslatesFields;
use BoldWeb\StatamicAiAssistant\Support\JsonObjectExtractor;
use BoldWeb\StatamicAiAssistant\Tools\ChatToolRunner;
use BoldWeb\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use BoldWeb\StatamicAiAssistant\Tools\SaveImageTool;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;
use BoldWeb\StatamicAiAssistant\Tools\UrlFetchTool;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fieldtypes\Terms;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class EntryGeneratorService
{
    use TranslatesFields;

    private const GEN_TEXT_TYPES = ['text', 'textarea', 'ai_text', 'ai_textarea'];

    private const GEN_HTML_TYPES = ['bard'];

    private const GEN_CHOICE_TYPES = ['select', 'button_group'];

    private const GEN_BOOLEAN_TYPES = ['toggle'];

    private const GEN_DATE_TYPES = ['date'];

    private const GEN_SKIP_TYPES = ['assets', 'section', 'color'];

    /**
     * @var array<string, array{by_lower_slug: array<string, string>, by_lower_title: array<string, string>}>
     */
    private array $taxonomyTermMatchCache = [];

    private const GEN_GROUP_TYPES = ['group'];

    private const GEN_LINK_TYPES = ['link'];

    private const GEN_RECURSIVE_TYPES = ['replicator', 'grid', 'components'];

    private AbstractAiService $aiService;

    private EntryGeneratorAssetResolver $assetResolver;

    private EntryGeneratorLinkFallback $linkFallback;

    private PromptUrlFetcher $promptUrlFetcher;

    private SetHintsService $setHints;

    private ?FigmaContentFetcher $figma;

    private ?RemoteImageFetcher $imageFetcher;

    private EntryStructureSerializer $structureSerializer;

    public function __construct(
        AbstractAiService $aiService,
        ?EntryGeneratorAssetResolver $assetResolver = null,
        ?EntryGeneratorLinkFallback $linkFallback = null,
        ?PromptUrlFetcher $promptUrlFetcher = null,
        ?SetHintsService $setHints = null,
        ?FigmaContentFetcher $figma = null,
        ?RemoteImageFetcher $imageFetcher = null,
        ?EntryStructureSerializer $structureSerializer = null,
    ) {
        $this->aiService = $aiService;
        $this->assetResolver = $assetResolver ?? new EntryGeneratorAssetResolver;
        $this->linkFallback = $linkFallback ?? new EntryGeneratorLinkFallback;
        $this->promptUrlFetcher = $promptUrlFetcher ?? new PromptUrlFetcher;
        $this->setHints = $setHints ?? new SetHintsService;
        $this->figma = $figma;
        $this->imageFetcher = $imageFetcher;
        $this->structureSerializer = $structureSerializer ?? new EntryStructureSerializer;
    }

    /**
     * Generate content for an entry from a prompt (does NOT save).
     *
     * @param  callable(string): void|null  $onStreamToken  Optional callback for each streamed assistant text delta (NDJSON / CP drawer)
     * @param  array{appendix: string, warnings: array<int, string>, preferred: \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug  When set (multi-entry stream), reuses one Jina text fetch and appendix so each entry does not re-fetch URLs.
     * @param  callable(): void|null  $streamHeartbeat  Optional NDJSON keepalive between tool rounds (same as planner stream).
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    public function generateContent(
        string $collectionHandle,
        string $blueprintHandle,
        string $prompt,
        string $locale,
        ?string $attachmentContent = null,
        ?callable $onStreamToken = null,
        ?\BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths $preferredAssets = null,
        ?array $prefetchedUrlAug = null,
        ?callable $streamHeartbeat = null,
    ): array {
        $collection = Collection::findByHandle($collectionHandle);

        if (! $collection) {
            throw new \RuntimeException(__('Collection not found.'));
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $blueprintHandle
            ? $visible->keyBy->handle()->get($blueprintHandle)
            : null;

        if (! $blueprint) {
            $blueprint = $visible->first();
        }

        if (! $blueprint) {
            throw new \RuntimeException(__('Blueprint not found.'));
        }

        $fieldSchema = $this->buildFieldSchema($blueprint, $locale);
        $systemMessage = $this->buildSystemMessage($fieldSchema, $locale);

        $useUrlTool = (bool) config('statamic-ai-assistant.entry_generator_fetch_url_tool', true)
            && (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)
            && $this->aiService->supportsChatTools();

        if ($prefetchedUrlAug !== null) {
            $urlAug = [
                'appendix' => $prefetchedUrlAug['appendix'],
                'warnings' => $prefetchedUrlAug['warnings'],
                'preferred' => $prefetchedUrlAug['preferred'],
            ];
            $appendedToPrompts = (bool) ($prefetchedUrlAug['appended_to_prompts'] ?? false);
            if ($appendedToPrompts) {
                $figmaAug = ['appendix' => '', 'warnings' => []];
                $combinedAppendix = '';
            } else {
                $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
                $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];
            }
        } elseif ($useUrlTool) {
            // Skip server-side URL pre-fetch when the LLM can call fetch_page_content itself.
            // Otherwise the page body is inlined and the model has no reason to drill into detail pages.
            $urlAug = [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths,
            ];
            $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
            $combinedAppendix = $figmaAug['appendix'];
        } else {
            $urlAug = $this->promptUrlFetcher->buildAugmentation($prompt);
            $figmaAug = $this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []];
            $combinedAppendix = $urlAug['appendix'].$figmaAug['appendix'];
        }

        $userMessage = $this->buildUserMessage($prompt, $attachmentContent, $combinedAppendix);

        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $maxTokens = (int) config('statamic-ai-assistant.generator_max_tokens', 4000);
        $toolWarnings = [];

        // Images the LLM copies from the source via save_remote_image land here;
        // merged into the preferred-asset queue below so the resolver assigns
        // them to this entry's image fields (matched by container).
        $fetchedImages = new \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
        $imageContainerHint = $this->assetResolver->primaryAssetContainer($blueprint);

        if ($useUrlTool) {
            try {
                $rawResponse = $this->generateContentWithUrlFetchToolLoop(
                    $messages,
                    $maxTokens,
                    $onStreamToken,
                    $toolWarnings,
                    $fetchedImages,
                    $imageContainerHint,
                    true,
                    $streamHeartbeat,
                );
            } catch (\Throwable $e) {
                Log::warning('[entry-gen-tool] tool loop failed; falling back to single-shot completion', [
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $rawResponse = $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);
            }
        } else {
            $rawResponse = $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);
        }

        if ($rawResponse === null || $rawResponse === '') {
            throw new \RuntimeException(__('The AI returned no content. Check your provider settings and try again.'));
        }

        $parsedData = $this->parseResponse($rawResponse);

        // Merge any preferred assets surfaced by the URL augmentation step
        // (single-prompt path) with the explicit ones passed in by the caller.
        // Either or both can be empty.
        $aggregatedPreferred = \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths::merge(
            $fetchedImages,
            $preferredAssets ?? new \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths,
            $urlAug['preferred'] ?? new \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths,
        );

        $result = $this->mapToFieldData($parsedData, $blueprint, $locale, $prompt, $aggregatedPreferred);

        if ($prefetchedUrlAug === null) {
            foreach ($urlAug['warnings'] as $w) {
                $result['warnings'][] = $w;
            }
        }

        foreach ($figmaAug['warnings'] as $w) {
            $result['warnings'][] = $w;
        }

        foreach ($toolWarnings as $w) {
            $result['warnings'][] = $w;
        }

        return $result;
    }

    /**
     * Multi-turn completion: model may call fetch_page_content; tool results include echoed reason + body or error.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $toolWarningsOut
     * @param  callable(): void|null  $streamHeartbeat
     */
    private function generateContentWithUrlFetchToolLoop(
        array $messages,
        int $maxTokens,
        ?callable $onStreamToken,
        array &$toolWarningsOut,
        \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths $imageSink,
        ?string $imageContainerHint,
        bool $allowImageTool,
        ?callable $streamHeartbeat = null,
    ): string {
        // The image tool only makes sense on the create path, where empty asset
        // fields are auto-filled from the preferred-asset queue afterwards. On
        // the update path ($allowImageTool === false) the resolver is skipped, so
        // a saved image would upload but never attach — don't offer it there.
        // A null container hint means the blueprint has no asset field to receive
        // images, so saving them would just orphan files — skip the tool then too.
        $useImageTool = $allowImageTool
            && $this->imageFetcher !== null
            && $imageContainerHint !== null
            && (bool) config('statamic-ai-assistant.image_fetch.enabled', true);

        $imageRule = $useImageTool
            ? "6. To keep the source page's imagery, call **save_remote_image** for each image you want on the entry, passing its absolute **url** (from the fetched content) and a short **reason**. Save the hero/lead image first. Saved images are attached to the entry's image fields automatically — never place image URLs in text or rich-text fields.\n"
            : '';

        $toolHint = "URL HANDLING RULES — these take priority over any later JSON output rule:\n"
            ."1. If the user message references one or more http(s) URLs, you MUST call the **fetch_page_content** tool to retrieve them BEFORE producing any output. Never invent or guess content from a URL alone.\n"
            ."2. After fetching, inspect the returned content. If it is a listing or index page (multiple teasers, news cards, 'read more' links, item summaries with detail URLs), identify the specific item the user asked for and call **fetch_page_content** AGAIN on that item's detail page URL. Write the entry from the detail page body, not from the listing teaser.\n"
            ."3. If the user asked for 'the first', 'the latest', 'the next', 'the second', or a specific item from a listing, pick the URL that matches that intent and fetch it.\n"
            ."4. You may call the tool multiple times. Pass **url** (full link, https preferred) and **reason** (short: which item or which blueprint field this supports).\n"
            ."5. ONLY after you have the full source text you need, respond with the JSON object for the entry fields — no markdown fences, no commentary — exactly as required by the rules below.\n"
            .$imageRule
            ."- TITLE FROM SOURCE: when you copy a page's content, set the entry's title field to that page's main heading VERBATIM. Use the fetched result's **h1** value (or the \"Page title (H1)\" line / the first top-level \"# \" heading in the fetched content). Do NOT paraphrase, translate, shorten, or invent a new title. Only deviate when the user's prompt explicitly dictates a different title, or when the source page genuinely has no H1 (then derive a concise, faithful title from the content).\n"
            ."- If the user asks to base this entry on the layout/components of an existing entry, call **read_entry_structure** (pass its **entry_id**, or a title/slug **query**) to read that entry's sections in order, then reproduce the same structure. The reference entry may use a different blueprint: map each section onto a set/field that exists in THIS entry's schema below — never copy set handles that are not in this schema.\n"
            ."---\n";

        $working = $messages;
        // Prepend the URL rules. The downstream system message contains the field
        // schema (often >40k chars) followed by 'respond with ONLY a JSON object',
        // and putting the tool rules at the end leaves them buried where smaller
        // models miss them.
        $working[0]['content'] = $toolHint."\n".($working[0]['content'] ?? '');

        // Shared tool runner: one dispatch + per-tool call budgets for both this
        // loop and the planner. Read tools (fetch/image/read-entry) implement the
        // ChatTool contract; the runner owns the tool_call → tool-reply plumbing.
        $toolContext = new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            onStreamToken: $onStreamToken,
            heartbeat: $streamHeartbeat,
            imageSink: $imageSink,
            imageContainerHint: $imageContainerHint,
        );

        $toolset = [new UrlFetchTool($this->promptUrlFetcher)];
        if ($useImageTool && $this->imageFetcher !== null) {
            $toolset[] = new SaveImageTool($this->imageFetcher);
        }
        $toolset[] = new ReadEntryStructureTool(
            $this->structureSerializer,
            fn (?string $collection, string $query, int $limit) => $this->findEntriesShortlist($collection, $query, $limit),
        );
        $runner = new ChatToolRunner($toolset, $toolContext);

        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);

        // Detect URLs in the user message(s). If any are present, the model MUST
        // call the fetch tool on the first round, otherwise it tends to fabricate
        // content from the URL slug instead of fetching.
        $promptHasUrl = false;
        foreach ($messages as $m) {
            $content = $m['content'] ?? '';
            if (($m['role'] ?? '') === 'user' && is_string($content)
                && preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $content)) {
                $promptHasUrl = true;
                break;
            }
        }

        for ($round = 0; $round < $maxRounds; $round++) {
            $streamHeartbeat?->__invoke();

            $forceTool = $promptHasUrl && $runner->callCount('fetch_page_content') === 0;
            // Use the explicit named-tool form on the first round when a URL is
            // present. Some OpenAI-compatible providers (notably Infomaniak's
            // Mistral deployments) silently ignore tool_choice: 'required' but
            // honor a specific {type:function, function:{name:...}} directive.
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            $data = $this->aiService->createChatCompletion($working, $maxTokens, $runner->definitions(), $toolChoice, $streamHeartbeat);
            $choice = $data['choices'][0] ?? null;

            if (! is_array($choice)) {
                throw new \RuntimeException(__('Unexpected AI response shape.'));
            }

            $msg = $choice['message'] ?? null;

            if (! is_array($msg)) {
                throw new \RuntimeException(__('Unexpected AI response shape.'));
            }

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            // When we forced the tool and the model still didn't call it, dump
            // the raw response so we can see whether the provider returned an
            // error/warning field, an unexpected message shape, or stripped the
            // tool_choice silently.
            if ($forceTool && ! $hasToolCalls) {
                Log::warning('[entry-gen-tool] forced tool call was IGNORED by model/provider', [
                    'round' => $round,
                    'tool_choice_sent' => $toolChoice,
                    'raw_response' => Str::limit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 2000),
                ]);
            }

            if ($hasToolCalls) {
                $working[] = [
                    'role' => 'assistant',
                    'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
                    'tool_calls' => $toolCalls,
                ];

                // Single shared dispatch: per-tool budgets, JSON encoding and the
                // role:tool replies are all handled by the runner.
                $runner->consume($toolCalls, $working);

                continue;
            }

            $text = $this->extractTextFromChatMessage($msg);

            if ($text !== '') {
                Log::info('[entry-gen-tool] generation done', [
                    'rounds' => $round + 1,
                    'fetches' => $runner->callCount('fetch_page_content'),
                    'text_chars' => strlen($text),
                ]);

                return $text;
            }

            throw new \RuntimeException(__('The AI returned no usable text after tool use.'));
        }

        Log::error('[entry-gen-tool] tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
        ]);
        throw new \RuntimeException(__('Entry generation stopped: too many tool rounds.'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractTextFromChatMessage(array $message): string
    {
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    /**
     * Save generated data as a new entry (draft).
     */
    public function saveEntry(
        string $collectionHandle,
        string $blueprintHandle,
        string $locale,
        array $data,
    ): StatamicEntry {
        return $this->createEntry($collectionHandle, $blueprintHandle, $locale, $data);
    }

    /**
     * Collections visible for entry generation (non-hidden blueprints only).
     *
     * When $entriesPerCollection > 0, each row also carries `count` (total entries
     * in the active site) and `entries` (recent shortlist: id/title/slug). This
     * extra payload is only used by the agentic planner so the LLM can reason
     * about updating existing entries without an extra tool round-trip.
     *
     * @return array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>, count?: int, entries?: array<int, array{id: string, title: string, slug: string}>}>
     */
    public function getCollectionsCatalog(int $entriesPerCollection = 0): array
    {
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        return Collection::all()
            ->map(function ($collection) use ($entriesPerCollection, $siteHandle) {
                $blueprints = $collection->entryBlueprints()
                    ->reject->hidden()
                    ->values()
                    ->map(function ($bp) {
                        return [
                            'handle' => $bp->handle(),
                            'title' => $bp->title(),
                        ];
                    });

                $row = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'blueprints' => $blueprints->values()->all(),
                ];

                if ($entriesPerCollection > 0) {
                    $row['count'] = (int) Entry::query()
                        ->where('collection', $collection->handle())
                        ->where('site', $siteHandle)
                        ->count();

                    $row['entries'] = Entry::query()
                        ->where('collection', $collection->handle())
                        ->where('site', $siteHandle)
                        ->orderBy('updated_at', 'desc')
                        ->limit($entriesPerCollection)
                        ->get()
                        ->map(fn ($e) => [
                            'id' => (string) $e->id(),
                            'title' => (string) ($e->value('title') ?? ''),
                            'slug' => (string) ($e->slug() ?? ''),
                        ])
                        ->values()
                        ->all();
                }

                return $row;
            })
            ->filter(fn ($row) => $row['blueprints'] !== [])
            ->values()
            ->all();
    }

    /**
     * Search entries by title/slug for the agentic planner's `find_entries` tool.
     * Kept in this service so it can reuse the same query path as the catalog shortlist.
     *
     * @return array<int, array{id: string, title: string, slug: string, collection: string}>
     */
    public function findEntriesShortlist(?string $collectionHandle, string $query, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        $q = Entry::query()->where('site', $siteHandle);

        if (is_string($collectionHandle) && $collectionHandle !== '') {
            $q->where('collection', $collectionHandle);
        }

        $needle = trim($query);
        if ($needle !== '') {
            $q->where(function ($qq) use ($needle) {
                $qq->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('slug', 'like', '%'.$needle.'%');
            });
        }

        return $q->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($e) => [
                'id' => (string) $e->id(),
                'title' => (string) ($e->value('title') ?? ''),
                'slug' => (string) ($e->slug() ?? ''),
                'collection' => (string) ($e->collectionHandle() ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Ask the LLM which collection and blueprint best match the user request.
     * Falls back to the "pages" collection when unsure; if missing, the first catalog entry.
     *
     * @param  array{appendix: string, warnings: array<int, string>}|null  $prefetchedUrlAug  When null, URLs are fetched once from $prompt.
     * @param  array{appendix: string, warnings: array<int, string>}|null  $prefetchedFigmaAug  When null, Figma links are resolved when a fetcher is available.
     * @return array{collection: string, blueprint: string}
     */
    public function resolveTargetFromPrompt(string $prompt, ?string $attachmentContent = null, ?array $prefetchedUrlAug = null, ?array $prefetchedFigmaAug = null): array
    {
        $catalog = $this->getCollectionsCatalog();

        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $urlAug = $prefetchedUrlAug ?? $this->promptUrlFetcher->buildAugmentation($prompt);
        $figmaAug = $prefetchedFigmaAug ?? ($this->figma ? $this->figma->buildAugmentation($prompt) : ['appendix' => '', 'warnings' => []]);

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $attachmentPart = $attachmentContent
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachmentContent, 6000)
            : '';

        $system = 'You are a Statamic CMS assistant. Given the user\'s request and the list of collections with their entry blueprints, choose the single best collection and blueprint for a new entry.'
            .' Return ONLY a JSON object with exactly two string keys: "collection" (the collection handle) and "blueprint" (the blueprint handle). The handles must match the catalog exactly (case-sensitive).'
            .' Prefer the most specific collection that fits the topic. If you are unsure, several fit equally, or none clearly apply, use the collection with handle "pages" if it exists in the catalog; otherwise use the first collection in the catalog.'
            .' The blueprint must be one of the blueprints listed for that collection.'
            .' Do not include markdown fences or any text outside the JSON.';

        $user = "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n{$prompt}{$urlAug['appendix']}{$figmaAug['appendix']}{$attachmentPart}";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        // Lightweight classification (pick collection + blueprint) — use the fast model tier.
        $raw = $this->aiService->usingFastModel(fn () => $this->aiService->generateFromMessages($messages, 256));

        if ($raw === null || trim($raw) === '') {
            return $this->fallbackTargetSelection($catalog);
        }

        try {
            $parsed = $this->parseTargetSelectionResponse($raw);
            $collectionHandle = isset($parsed['collection']) && is_string($parsed['collection']) ? trim($parsed['collection']) : '';
            $blueprintHandle = isset($parsed['blueprint']) && is_string($parsed['blueprint']) ? trim($parsed['blueprint']) : '';
        } catch (\RuntimeException) {
            return $this->fallbackTargetSelection($catalog);
        }

        if ($this->validateTargetSelection($catalog, $collectionHandle, $blueprintHandle)) {
            return ['collection' => $collectionHandle, 'blueprint' => $blueprintHandle];
        }

        return $this->fallbackTargetSelection($catalog);
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @return array{collection: string, blueprint: string}
     */
    private function fallbackTargetSelection(array $catalog): array
    {
        foreach ($catalog as $row) {
            if (($row['handle'] ?? '') === 'pages' && ($row['blueprints'][0]['handle'] ?? '') !== '') {
                return [
                    'collection' => 'pages',
                    'blueprint' => $row['blueprints'][0]['handle'],
                ];
            }
        }

        $first = $catalog[0];

        return [
            'collection' => $first['handle'],
            'blueprint' => $first['blueprints'][0]['handle'],
        ];
    }

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     */
    private function validateTargetSelection(array $catalog, string $collectionHandle, string $blueprintHandle): bool
    {
        if ($collectionHandle === '' || $blueprintHandle === '') {
            return false;
        }

        foreach ($catalog as $row) {
            if ($row['handle'] !== $collectionHandle) {
                continue;
            }

            foreach ($row['blueprints'] as $bp) {
                if (($bp['handle'] ?? '') === $blueprintHandle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTargetSelectionResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $jsonStr = JsonObjectExtractor::firstObject($response);

        if ($jsonStr === null) {
            $firstBrace = strpos($response, '{');
            $lastBrace = strrpos($response, '}');

            if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
                $this->logUnparseableResponse('target_selection', $rawResponse);
                throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
            }

            $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logUnparseableResponse('target_selection', $rawResponse, $e->getMessage());
            throw new \RuntimeException(__('Invalid JSON in AI response: :message', ['message' => $e->getMessage()]));
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->logUnparseableResponse('target_selection', $rawResponse, 'response was not a JSON object');
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }

    /**
     * Field schema for the CP review step (includes assets; LLM schema excludes them).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFieldSchemaForPreview(Blueprint $blueprint): array
    {
        $schema = [];
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        foreach ($blueprint->fields()->all() as $field) {
            $type = $field->type();

            if ($type === 'section') {
                continue;
            }

            if ($type === 'assets') {
                $schema[$field->handle()] = [
                    'label' => $field->display(),
                    'generatable' => true,
                    'type' => 'asset_description',
                ];

                continue;
            }

            $entry = $this->buildFieldSchemaEntry($field, false, $siteHandle);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * Build a JSON-serializable schema from the blueprint for the LLM.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildFieldSchema(Blueprint $blueprint, string $siteHandle): array
    {
        $schema = [];

        foreach ($blueprint->fields()->all() as $field) {
            $entry = $this->buildFieldSchemaEntry($field, false, $siteHandle);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function finalizeSchemaEntry(Field $field, array $entry): array
    {
        if ($field->isRequired()) {
            $entry['required'] = true;
        }

        // Field hints (BOLD agent settings): editors can describe what a field
        // like `hero_title` or `lead` is for and add writing guidelines. Hints
        // match by handle wherever the field appears (top level, groups, sets).
        $hint = $this->setHints->forField((string) $field->handle());

        if ($hint !== null) {
            if ($hint['ai_description'] !== '') {
                $entry['ai_description'] = $hint['ai_description'];
            }

            if ($hint['when_to_use'] !== []) {
                $entry['writing_guidelines'] = $hint['when_to_use'];
            }
        }

        return $entry;
    }

    /**
     * @return array<int, string>
     */
    private function getTermsFieldTaxonomyHandles(Field $field): array
    {
        $configTax = $field->get('taxonomies');

        if ($configTax === null || $configTax === []) {
            return Taxonomy::handles()->values()->all();
        }

        $out = [];

        foreach (Arr::wrap($configTax) as $item) {
            if (is_string($item) || is_int($item)) {
                $h = trim((string) $item);

                if ($h !== '') {
                    $out[] = $h;
                }

                continue;
            }

            if (is_array($item)) {
                $h = $item['handle'] ?? $item['value'] ?? $item['taxonomy'] ?? null;

                if (is_string($h) && trim($h) !== '') {
                    $out[] = trim($h);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  iterable<int, \Statamic\Taxonomies\Term>  $terms
     */
    private function warmTaxonomyTermCache(string $siteHandle, string $taxonomyHandle, iterable $terms): void
    {
        $key = $siteHandle.'|'.$taxonomyHandle;
        $byLowerSlug = [];
        $byLowerTitle = [];

        foreach ($terms as $term) {
            $slug = $term->slug();
            $title = (string) ($term->get('title') ?? $slug);
            $byLowerSlug[strtolower($slug)] = $slug;
            $byLowerTitle[strtolower(trim($title))] = $slug;
        }

        $this->taxonomyTermMatchCache[$key] = [
            'by_lower_slug' => $byLowerSlug,
            'by_lower_title' => $byLowerTitle,
        ];
    }

    private function ensureTaxonomyTermCache(string $siteHandle, string $taxonomyHandle): void
    {
        $key = $siteHandle.'|'.$taxonomyHandle;

        if (isset($this->taxonomyTermMatchCache[$key])) {
            return;
        }

        $terms = Term::query()
            ->where('site', $siteHandle)
            ->where('taxonomy', $taxonomyHandle)
            ->get();

        $this->warmTaxonomyTermCache($siteHandle, $taxonomyHandle, $terms);
    }

    private function resolveSlugInTaxonomy(string $raw, string $taxonomyHandle, string $siteHandle): ?string
    {
        $this->ensureTaxonomyTermCache($siteHandle, $taxonomyHandle);
        $key = $siteHandle.'|'.$taxonomyHandle;
        $cache = $this->taxonomyTermMatchCache[$key];
        $lower = strtolower(trim($raw));

        if (isset($cache['by_lower_slug'][$lower])) {
            return $cache['by_lower_slug'][$lower];
        }

        if (isset($cache['by_lower_title'][$lower])) {
            return $cache['by_lower_title'][$lower];
        }

        $lang = Site::get($siteHandle)?->lang() ?? Site::default()->lang();
        $slugified = Str::slug($raw, '-', $lang);
        $lowerSlug = strtolower($slugified);

        if ($slugified !== '' && isset($cache['by_lower_slug'][$lowerSlug])) {
            return $cache['by_lower_slug'][$lowerSlug];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildTermsFieldSchemaPayload(Field $field, string $siteHandle, ?string $instructions): ?array
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->getTermsFieldTaxonomyHandles($field);

        if ($taxHandles === []) {
            return null;
        }

        $catalog = [];

        foreach ($taxHandles as $th) {
            $taxonomy = Taxonomy::findByHandle($th);

            if (! $taxonomy) {
                continue;
            }

            $terms = Term::query()
                ->where('site', $siteHandle)
                ->where('taxonomy', $th)
                ->orderBy('slug')
                ->get();

            $this->warmTaxonomyTermCache($siteHandle, $th, $terms);

            $termEntries = [];

            foreach ($terms as $term) {
                $termEntries[] = [
                    'slug' => $term->slug(),
                    'title' => (string) ($term->get('title') ?? $term->slug()),
                ];
            }

            $catalog[] = [
                'taxonomy' => $th,
                'title' => $taxonomy->title(),
                'terms' => $termEntries,
            ];
        }

        if ($catalog === []) {
            return null;
        }

        $single = $ft->usingSingleTaxonomy();
        $maxItems = $field->get('max_items');
        $descParts = [];

        if ($instructions) {
            $descParts[] = $instructions;
        }

        $descParts[] = $single
            ? 'Choose from the listed term slugs for this taxonomy. Output must use the exact slug from the list (you may infer the best match from the user prompt).'
            : 'Use taxonomy_handle::term_slug for each value, using only slugs from the corresponding taxonomy list in the catalog.';

        if ($maxItems === 1) {
            $descParts[] = $single
                ? 'Return a single slug string, not an array.'
                : 'Return a single taxonomy_handle::term_slug string, not an array.';
        } elseif ($maxItems !== null) {
            $descParts[] = "Return at most {$maxItems} ".($single ? 'slugs' : 'taxonomy_handle::term_slug values').'.';
        } else {
            $descParts[] = $single
                ? 'Return an array of slugs when multiple terms apply.'
                : 'Return an array of taxonomy_handle::term_slug strings when multiple terms apply.';
        }

        return [
            'label' => $field->display(),
            'generatable' => true,
            'type' => 'taxonomy_terms',
            'taxonomies' => $catalog,
            'single_taxonomy' => $single,
            'max_items' => $maxItems,
            'description' => implode(' ', $descParts),
        ];
    }

    /**
     * @param  array<int, string>  $taxHandles
     * @return list<array{tax: ?string, raw: string}>
     */
    private function flattenTermsLlmValue(mixed $value, bool $singleTaxonomy, array $taxHandles): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_array($value) && Arr::isAssoc($value)) {
            if ($singleTaxonomy) {
                $first = reset($value);

                if (! is_scalar($first)) {
                    return [];
                }

                $raw = trim((string) $first);

                if ($raw === '') {
                    return [];
                }

                return [['tax' => $taxHandles[0] ?? null, 'raw' => $raw]];
            }

            $out = [];

            foreach ($value as $k => $v) {
                $taxKey = is_string($k) || is_int($k) ? (string) $k : null;

                if ($taxKey === null) {
                    continue;
                }

                foreach (Arr::wrap($v) as $one) {
                    if (! is_scalar($one)) {
                        continue;
                    }

                    $raw = trim((string) $one);

                    if ($raw === '') {
                        continue;
                    }

                    $out[] = ['tax' => $taxKey, 'raw' => $raw];
                }
            }

            return $out;
        }

        if (is_string($value) || is_numeric($value)) {
            $s = trim((string) $value);

            if ($s === '') {
                return [];
            }

            if ($singleTaxonomy) {
                return [['tax' => $taxHandles[0] ?? null, 'raw' => $s]];
            }

            if (str_contains($s, '::')) {
                [$t, $r] = explode('::', $s, 2);

                return [['tax' => trim($t), 'raw' => trim($r)]];
            }

            return [['tax' => null, 'raw' => $s]];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $out = array_merge($out, $this->flattenTermsLlmValue((string) $item, $singleTaxonomy, $taxHandles));
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $taxHandles
     */
    private function resolveRawToTaxonomySlug(?string $taxonomyHint, string $raw, array $taxHandles, string $siteHandle, Field $field, array &$warnings): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '::')) {
            [$taxonomyHint, $raw] = explode('::', $raw, 2);
            $taxonomyHint = trim((string) $taxonomyHint);
            $raw = trim($raw);
        }

        if ($taxonomyHint !== null && $taxonomyHint !== '' && ! in_array($taxonomyHint, $taxHandles, true)) {
            $warnings[] = __(':field: unknown taxonomy ":t".', [
                'field' => $field->display(),
                't' => $taxonomyHint,
            ]);

            return null;
        }

        if ($taxonomyHint !== null && $taxonomyHint !== '') {
            $slug = $this->resolveSlugInTaxonomy($raw, $taxonomyHint, $siteHandle);

            if ($slug === null) {
                $warnings[] = __(':field: no matching term for ":raw" in taxonomy :tax.', [
                    'field' => $field->display(),
                    'raw' => $raw,
                    'tax' => $taxonomyHint,
                ]);

                return null;
            }

            return "{$taxonomyHint}::{$slug}";
        }

        if (count($taxHandles) === 1) {
            $th = $taxHandles[0];
            $slug = $this->resolveSlugInTaxonomy($raw, $th, $siteHandle);

            if ($slug === null) {
                $warnings[] = __(':field: no matching term for ":raw".', [
                    'field' => $field->display(),
                    'raw' => $raw,
                ]);

                return null;
            }

            return "{$th}::{$slug}";
        }

        foreach ($taxHandles as $th) {
            $slug = $this->resolveSlugInTaxonomy($raw, $th, $siteHandle);

            if ($slug !== null) {
                return "{$th}::{$slug}";
            }
        }

        $warnings[] = __(':field: no matching term for ":raw" in any configured taxonomy.', [
            'field' => $field->display(),
            'raw' => $raw,
        ]);

        return null;
    }

    private function mapTermsFieldValue(mixed $value, Field $field, array &$warnings, string $siteHandle): mixed
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->getTermsFieldTaxonomyHandles($field);

        if ($taxHandles === []) {
            return null;
        }

        $singleTax = $ft->usingSingleTaxonomy();
        $flat = $this->flattenTermsLlmValue($value, $singleTax, $taxHandles);

        if ($flat === []) {
            return null;
        }

        $resolved = [];

        foreach ($flat as $item) {
            $id = $this->resolveRawToTaxonomySlug($item['tax'], $item['raw'], $taxHandles, $siteHandle, $field, $warnings);

            if ($id !== null) {
                $resolved[] = $id;
            }
        }

        $resolved = array_values(array_unique($resolved));

        if ($resolved === []) {
            return null;
        }

        $maxItems = $field->get('max_items');
        $maxItems = is_numeric($maxItems) ? (int) $maxItems : null;

        if ($maxItems !== null && count($resolved) > $maxItems) {
            $resolved = array_slice($resolved, 0, $maxItems);
            $warnings[] = __(':field: too many taxonomy terms; only the first :n kept.', [
                'field' => $field->display(),
                'n' => $maxItems,
            ]);
        }

        $maxOne = $maxItems === 1;

        if ($singleTax) {
            $slugs = [];

            foreach ($resolved as $id) {
                $parts = explode('::', $id, 2);
                $slugs[] = $parts[1] ?? $parts[0];
            }

            if ($maxOne) {
                return $slugs[0] ?? null;
            }

            return $slugs;
        }

        if ($maxOne) {
            return $resolved[0] ?? null;
        }

        return $resolved;
    }

    private function firstTermValueForTermsField(Field $field, string $siteHandle): mixed
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->getTermsFieldTaxonomyHandles($field);
        $taxHandles = array_values($taxHandles);
        sort($taxHandles);

        foreach ($taxHandles as $th) {
            $term = Term::query()
                ->where('site', $siteHandle)
                ->where('taxonomy', $th)
                ->orderBy('slug')
                ->first();

            if (! $term) {
                continue;
            }

            $slug = $term->slug();
            $maxItemsCfg = $field->get('max_items');
            $maxOne = is_numeric($maxItemsCfg) && (int) $maxItemsCfg === 1;

            if ($ft->usingSingleTaxonomy()) {
                if ($maxOne) {
                    return $slug;
                }

                return [$slug];
            }

            $id = "{$th}::{$slug}";

            if ($maxOne) {
                return $id;
            }

            return [$id];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFieldSchemaEntry(\Statamic\Fields\Field $field, bool $insideReplicatorComponentsOrGridRow = false, ?string $siteHandle = null): ?array
    {
        $type = $field->type();
        $display = $field->display();
        $instructions = $field->instructions();
        $config = $field->config();
        $siteHandle = $siteHandle ?? Site::current()?->handle() ?? Site::default()->handle();

        if ($insideReplicatorComponentsOrGridRow && $type === 'assets') {
            return $this->finalizeSchemaEntry($field, [
                'label' => $display,
                'generatable' => true,
                'type' => 'asset_description',
                'description' => ($instructions ? $instructions.' ' : '')
                    .'Short vivid description of the image (subject, setting, mood). You may use an empty string for layout-only blocks; imagery may be auto-selected.',
            ]);
        }

        if ($type === 'terms') {
            $payload = $this->buildTermsFieldSchemaPayload($field, $siteHandle, $instructions);

            return $payload === null ? null : $this->finalizeSchemaEntry($field, $payload);
        }

        if (in_array($type, self::GEN_SKIP_TYPES)) {
            return null;
        }

        $entry = [
            'label' => $display,
            'generatable' => true,
        ];

        if ($instructions) {
            $entry['description'] = $instructions;
        }

        if (in_array($type, self::GEN_GROUP_TYPES)) {
            $entry['type'] = 'group';
            $entry['fields'] = [];

            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $subEntry = $this->buildFieldSchemaEntry($sub, $insideReplicatorComponentsOrGridRow, $siteHandle);

                if ($subEntry !== null) {
                    $entry['fields'][$sub->handle()] = $subEntry;
                }
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_LINK_TYPES)) {
            $entry['type'] = 'link';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide a full URL (https://...), a path starting with /, entry::UUID for an internal entry, asset::... for an asset, or @child when applicable.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if ($type === 'video') {
            $entry['type'] = 'video_url';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'YouTube or video page URL (https://...) or empty string.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_TEXT_TYPES)) {
            $entry['type'] = 'text';

            if (isset($config['character_limit'])) {
                $entry['max_length'] = $config['character_limit'];
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_HTML_TYPES)) {
            $entry['type'] = 'html';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide as HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_CHOICE_TYPES)) {
            $entry['type'] = 'select';
            $options = $config['options'] ?? [];

            if (is_array($options)) {
                $entry['options'] = array_values(
                    array_map(fn ($v) => is_array($v) ? ($v['value'] ?? $v['label'] ?? $v['key'] ?? '') : (string) $v, $options)
                );
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES)) {
            $entry['type'] = 'boolean';

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_DATE_TYPES)) {
            $entry['type'] = 'date';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .$this->statamicDateFieldSchemaDescriptionSuffix();

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if ($type === 'grid') {
            $entry['type'] = 'grid';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Repeating grid (a list/table). Provide an ARRAY OF ROWS where EACH row represents ONE discrete item. '
                .'Every row is a JSON object using the handles under "row_fields" as its keys, and has NO "type" key. '
                .'When the source material for this field is a list of discrete items (bullet points, key/value pairs, dates, features, steps, FAQ entries), create ONE row per item — never combine several items into a single row field. '
                .'Use a single row only when the content is genuinely one item.';

            $rowFields = $this->buildGridRowSchema($field, $siteHandle);

            if (! empty($rowFields)) {
                $entry['row_fields'] = $rowFields;
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES)) {
            $entry['type'] = 'structured';
            $entry['description'] = ($instructions ? $instructions.' ' : '')
                .'Provide as an array of ordered blocks. Each object must include a "type" key matching a set handle from the schema "sets" keys (see set_layout_catalog when present for titles, ai_description, and when_to_use rules). '
                .'Choose each block based on its ai_description (what the block is) and when_to_use (the scenarios it was designed for) — pick the block whose purpose genuinely matches the content of that section, rather than reusing a safe default. '
                .'Use several different block types across the page when the schema offers them — do not default the whole page to one or two repetitive types (for example only plain text or teaser blocks) if other sets exist. '
                .'Include visual or image-led sets where they fit the narrative, not only text-heavy blocks.';

            $sets = $this->buildRecursiveSchema($field, $siteHandle);

            if (! empty($sets)) {
                $entry['sets'] = $sets;
            }

            if (in_array($type, ['replicator', 'components'], true)) {
                $catalog = $this->buildSetLayoutCatalog($field);

                if ($catalog !== []) {
                    $entry['set_layout_catalog'] = $catalog;
                }
            }

            return $this->finalizeSchemaEntry($field, $entry);
        }

        // Unknown field type — skip
        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRecursiveSchema(\Statamic\Fields\Field $field, string $siteHandle): array
    {
        $sets = [];
        $fieldtype = $field->fieldtype();

        if (in_array($field->type(), ['replicator', 'components'])) {
            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                $setFields = $fieldtype->fields($setHandle);
                $setSchema = [];

                foreach ($setFields->all() as $subField) {
                    $subEntry = $this->buildFieldSchemaEntry($subField, true, $siteHandle);

                    if ($subEntry !== null) {
                        $setSchema[$subField->handle()] = $subEntry;
                    }
                }

                $sets[$setHandle] = $setSchema;
            }
        }

        return $sets;
    }

    /**
     * Schema for a single grid row: the grid's sub-fields keyed by handle.
     * Grids are a repeating list of identically-shaped rows (unlike
     * replicator/components, which pick between named sets), so this is
     * presented to the model under "row_fields" rather than "sets".
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildGridRowSchema(\Statamic\Fields\Field $field, string $siteHandle): array
    {
        $rowSchema = [];

        foreach ($field->fieldtype()->fields()->all() as $subField) {
            $subEntry = $this->buildFieldSchemaEntry($subField, true, $siteHandle);

            if ($subEntry !== null) {
                $rowSchema[$subField->handle()] = $subEntry;
            }
        }

        return $rowSchema;
    }

    /**
     * Human-oriented summary of each replicator / components set for the LLM.
     *
     * @return array<int, array{type_handle: string, title: string, content_mix: string}>
     */
    private function buildSetLayoutCatalog(\Statamic\Fields\Field $field): array
    {
        if (! in_array($field->type(), ['replicator', 'components'], true)) {
            return [];
        }

        $fieldtype = $field->fieldtype();
        $catalog = [];

        foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
            $title = is_array($setConfig) && isset($setConfig['display'])
                ? (string) $setConfig['display']
                : Str::headline(str_replace('_', ' ', (string) $setHandle));

            try {
                $setFields = $fieldtype->fields($setHandle);
            } catch (\Exception) {
                continue;
            }

            $entry = [
                'type_handle' => (string) $setHandle,
                'title' => $title,
                'content_mix' => $this->describeSetContentMixForCatalog($setFields),
            ];

            $hint = $this->setHints->forSet((string) $setHandle);

            if ($hint !== null) {
                if (($hint['ai_description'] ?? '') !== '') {
                    $entry['ai_description'] = $hint['ai_description'];
                }

                if (! empty($hint['when_to_use'])) {
                    $entry['when_to_use'] = array_values($hint['when_to_use']);
                }
            }

            $catalog[] = $entry;
        }

        return $catalog;
    }

    /**
     * Short phrase describing dominant field kinds in a set (guides layout variety).
     */
    private function describeSetContentMixForCatalog(\Statamic\Fields\Fields $setFields): string
    {
        $tags = [];

        foreach ($setFields->all() as $f) {
            $t = $f->type();

            if ($t === 'assets') {
                $tags['images'] = 'images / visual';
            } elseif (in_array($t, self::GEN_HTML_TYPES, true)) {
                $tags['html'] = 'rich text';
            } elseif (in_array($t, self::GEN_TEXT_TYPES, true)) {
                $tags['text'] = 'short text';
            } elseif (in_array($t, self::GEN_LINK_TYPES, true)) {
                $tags['link'] = 'links';
            } elseif ($t === 'video') {
                $tags['video'] = 'video';
            } elseif (in_array($t, self::GEN_CHOICE_TYPES, true) || in_array($t, self::GEN_BOOLEAN_TYPES, true)) {
                $tags['control'] = 'choices / toggles';
            } elseif ($t === 'terms') {
                $tags['taxonomy'] = 'taxonomy / categories';
            } elseif (in_array($t, self::GEN_RECURSIVE_TYPES, true)) {
                $tags['nested'] = 'nested layout';
            } elseif ($t === 'group') {
                $inner = $this->describeSetContentMixForCatalog($f->fieldtype()->fields());
                if ($inner !== '') {
                    $tags['group_'.$f->handle()] = $inner;
                }
            }
        }

        $parts = array_values(array_unique(array_filter(array_values($tags))));

        return $parts !== [] ? implode(', ', $parts) : 'layout block';
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function fieldSchemaContainsStructuredType(array $schema): bool
    {
        return $this->fieldSchemaContainsType($schema, 'structured');
    }

    private function fieldSchemaContainsTaxonomyTerms(array $schema): bool
    {
        return $this->fieldSchemaContainsType($schema, 'taxonomy_terms');
    }

    /**
     * Whether any field entry in the schema (or nested inside a group's
     * "fields", a replicator/components "sets", or a grid's "row_fields") has
     * the given schema type.
     *
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function fieldSchemaContainsType(array $schema, string $type): bool
    {
        foreach ($schema as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) === $type) {
                return true;
            }

            foreach (['fields', 'row_fields'] as $nestedKey) {
                if (isset($entry[$nestedKey]) && is_array($entry[$nestedKey]) && $this->fieldSchemaContainsType($entry[$nestedKey], $type)) {
                    return true;
                }
            }

            if (isset($entry['sets']) && is_array($entry['sets'])) {
                foreach ($entry['sets'] as $setSchema) {
                    if (is_array($setSchema) && $this->fieldSchemaContainsType($setSchema, $type)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function buildSystemMessage(array $fieldSchema, string $locale): string
    {
        $preface = config('statamic-ai-assistant.prompt_generator_preface',
            'You are a CMS content creation assistant. Generate structured content for website entries. Respond ONLY with a valid JSON object — no markdown fences, no commentary.'
        );

        $schemaJson = json_encode($fieldSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $structuredRules = '';

        if ($this->fieldSchemaContainsTaxonomyTerms($fieldSchema)) {
            $structuredRules .= "\n"
                ."- For fields with type \"taxonomy_terms\": pick values only from the slugs listed under each taxonomy in \"taxonomies\". Output the exact slug (single-taxonomy fields) or \"taxonomy_handle::term_slug\" when \"single_taxonomy\" is false. You may infer the best choice from the user prompt.\n";
        }

        if ($this->fieldSchemaContainsStructuredType($fieldSchema)) {
            $structuredRules .= "\n"
                ."- For fields with type \"structured\" (replicator / components / grid): each array item is one block; include a \"type\" property with the set handle, then that set's field keys.\n"
                ."- Block selection: when set_layout_catalog is provided, treat it as the authoritative guide. For each block, read its \"ai_description\" (what the block is and how it renders) and its \"when_to_use\" list (the concrete scenarios it exists for). Pick a block ONLY when the section you are building matches one of its when_to_use triggers, or clearly fits its ai_description. If none match, pick the closest block by content_mix rather than force-fitting the most generic one.\n"
                ."- Treat when_to_use as strong editorial rules, not suggestions: if a block says it is for \"hero openers\", do not reuse it mid-page for unrelated content. If multiple blocks could fit, prefer the one whose when_to_use most specifically describes the section.\n"
                ."- Layout variety: deliberately mix several different type_handle values across the page. Do not default the whole page to one or two repetitive blocks (for example only plain text and teaser) when other sets with matching when_to_use / ai_description exist.\n"
                ."- Visual rhythm: include blocks whose ai_description, when_to_use, or content_mix mentions images, galleries, heroes, or visual layout when they support the narrative — not only text-heavy blocks.\n"
                ."- For type \"asset_description\" (inside sets): a concise phrase describing the desired image; empty string is allowed and imagery may be assigned automatically.\n";
        }

        if ($this->fieldSchemaContainsType($fieldSchema, 'grid')) {
            $structuredRules .= "\n"
                ."- For fields with type \"grid\": this is a REPEATING list/table, not a single rich-text block. Return an ARRAY OF ROWS. Each row is one discrete item — an object keyed by the handles under \"row_fields\", with NO \"type\" key.\n"
                ."- One item per row: when the field's source material is a list (bullet points, key/value pairs, dates, features, steps, FAQ entries), output ONE row per item. Do NOT pour the whole list into a single row's field, even when that row field is rich text / HTML.\n";
        }

        return $preface."\n\n"
            ."You MUST write all content in this language/locale: {$locale}\n\n"
            .$this->germanNoEszettInstructions($locale)
            ."Here is the field schema for the entry you need to create. The JSON keys in your response must exactly match the field handles (the keys below):\n\n"
            .$schemaJson."\n\n"
            ."Rules:\n"
            ."- Respond with ONLY a valid JSON object. No markdown code fences, no explanation.\n"
            ."- For fields with type \"text\": provide plain text only, no HTML.\n"
            ."- For fields with type \"html\": provide valid HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.\n"
            ."- For fields with type \"select\": choose one of the provided options.\n"
            ."- For fields with type \"boolean\": provide true or false.\n"
            .$this->statamicDateFieldRulesForPrompt()
            ."- For fields with type \"structured\": provide an array of objects, each with a \"type\" key matching a set handle, plus the set's field values.\n"
            ."- For fields with type \"grid\": provide an array of rows, each row an object keyed by the handles under \"row_fields\" (no \"type\" key). Each row is one item; make one row per discrete item.\n"
            ."- For fields with type \"group\": provide a JSON object whose keys match the nested field handles (see \"fields\" in the schema).\n"
            ."- For fields with type \"link\": provide a URL, path, or entry::UUID reference as described in the field description.\n"
            ."- For fields with type \"video_url\": provide a YouTube or video page URL, or an empty string.\n"
            ."- For fields with type \"taxonomy_terms\": use only slugs from the schema \"taxonomies\" lists (exact slug when \"single_taxonomy\" is true; otherwise taxonomy_handle::term_slug).\n"
            .$structuredRules
            ."- Field-level editorial guidance: when a field in the schema carries \"ai_description\" (what the field is and how it renders on the page) and/or \"writing_guidelines\" (concrete authoring rules such as length, tone, or structure), treat them as binding editorial instructions for THAT field's content — they override generic assumptions you would otherwise make from the handle alone.\n"
            ."- Every field in the schema that includes \"required\": true must have a non-empty, valid value for its type. Never omit those keys, never use empty strings for them, and never use HTML with no visible text. If the user is vague, says to do nothing, or gives minimal instructions, you must still invent sensible placeholder content so the entry would pass blueprint validation.\n"
            ."- For fields without \"required\": true, if you cannot determine content, use an empty string when appropriate.\n"
            ."- Generate meaningful, high-quality content that is relevant to the user's request.";
    }

    /**
     * Global Rules bullet: Statamic Date fieldtype values are plain Y-m-d strings in JSON.
     */
    private function statamicDateFieldRulesForPrompt(): string
    {
        return "- For fields with type \"date\" (Statamic **date** fieldtype): output a **JSON string** in calendar form **YYYY-MM-DD** only "
            .'(four-digit year, two-digit month, two-digit day, ASCII hyphens; example: `"2026-04-28"`). '
            .'Statamic stores PHP `Y-m-d` strings, not datetimes: do **not** use `T`, time zones, `Z`, or `2026-04-28T00:00:00`. '
            .'Do **not** use Unix timestamps, bare numbers, `DD.MM.YYYY`, `MM/DD/YYYY`, written-out months, or relative phrases like "today". '
            ."If the user prompt or fetched page shows another format, convert it to `YYYY-MM-DD`.\n";
    }

    /**
     * Appended to each date field's schema `description` so the model sees the contract next to the handle.
     */
    private function statamicDateFieldSchemaDescriptionSuffix(): string
    {
        return 'Value: JSON string `YYYY-MM-DD` only (Statamic date / PHP Y-m-d). No time portion, no regional numeric dates, no "today" — normalize from source text if needed.';
    }

    /**
     * Swiss-style German: never ß, always ss (project preference for generated copy).
     */
    private function germanNoEszettInstructions(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        if ($normalized === '' || ! str_starts_with($normalized, 'de')) {
            return '';
        }

        return "German orthography (mandatory for every German string you output, including titles and body text):\n"
            ."- NEVER use the letter ß (Eszett). Always use \"ss\" instead.\n"
            ."- Examples: \"Strasse\" not \"Straße\", \"gross\" not \"groß\", \"heiss\" not \"heiß\", \"dass\" stays \"dass\".\n\n";
    }

    private function buildUserMessage(string $prompt, ?string $attachmentContent, string $urlAppendix = ''): string
    {
        $message = $prompt;

        if ($urlAppendix !== '') {
            $message .= $urlAppendix;
        }

        if ($attachmentContent) {
            $message .= "\n\n--- ATTACHED DOCUMENT CONTENT ---\n\n".$attachmentContent;
        }

        return $message;
    }

    /**
     * Parse the LLM response, extracting JSON even if wrapped in markdown fences.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $rawResponse): array
    {
        $response = trim($rawResponse);

        // Strip markdown code fences
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/', '', $response);
        $response = trim($response);

        $jsonStr = JsonObjectExtractor::firstObject($response);

        if ($jsonStr === null) {
            $firstBrace = strpos($response, '{');
            $lastBrace = strrpos($response, '}');

            if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
                $this->logUnparseableResponse('generation', $rawResponse);
                throw new \RuntimeException(__('Could not parse AI response as JSON. Please try again.'));
            }

            $jsonStr = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        try {
            $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logUnparseableResponse('generation', $rawResponse, $e->getMessage());
            throw new \RuntimeException(__('Invalid JSON in AI response: :message', ['message' => $e->getMessage()]));
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->logUnparseableResponse('generation', $rawResponse, 'response was not a JSON object');
            throw new \RuntimeException(__('AI response must be a JSON object, not an array.'));
        }

        return $decoded;
    }

    /**
     * Log the raw AI response when JSON parsing fails, so a future failure can be
     * diagnosed from the logs (the model output is otherwise lost). The response
     * is capped to keep log lines manageable.
     */
    private function logUnparseableResponse(string $stage, string $rawResponse, ?string $error = null): void
    {
        Log::error('[entry-gen] could not parse AI JSON response', [
            'stage' => $stage,
            'error' => $error,
            'length' => strlen($rawResponse),
            'raw_response' => Str::limit($rawResponse, 20000),
        ]);
    }

    /**
     * Map parsed LLM data to Statamic field values.
     *
     * Returns both the Statamic-ready data and a display-friendly version
     * (e.g., HTML strings for Bard fields instead of ProseMirror JSON).
     *
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    private function mapToFieldData(array $parsedData, Blueprint $blueprint, string $locale, string $prompt = '', ?\BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths $preferredAssets = null, bool $partial = false): array
    {
        $data = [];
        $displayData = [];
        $warnings = [];

        foreach ($blueprint->fields()->all() as $field) {
            $handle = $field->handle();
            $type = $field->type();

            if (! array_key_exists($handle, $parsedData)) {
                continue;
            }

            $value = $parsedData[$handle];

            if ($value === null || $value === '') {
                continue;
            }

            // For Bard fields, keep the raw HTML for display
            if (in_array($type, self::GEN_HTML_TYPES) && is_string($value)) {
                $displayData[$handle] = $value;
            }

            $mapped = $this->mapFieldValue($value, $field, $warnings, $locale);

            if ($mapped !== null) {
                $data[$handle] = $mapped;

                // For non-bard fields, display value is same as mapped value
                if (! isset($displayData[$handle])) {
                    $displayData[$handle] = $mapped;
                }
            }
        }

        // Update mode: only carry forward what the LLM actually returned. Skip
        // the asset/link/required autofillers that exist for fresh-entry creation
        // — the existing entry already satisfies blueprint validation.
        if (! $partial) {
            $this->assetResolver->fillAssetFieldsWithRandom($data, $displayData, $blueprint, $warnings, $preferredAssets);
            $this->linkFallback->fillEmptyLinkFields($data, $displayData, $blueprint, $locale, $warnings);
            $this->applyMandatoryFieldFallbacks($data, $displayData, $blueprint, $warnings, $prompt, $locale);
        }

        return ['data' => $data, 'displayData' => $displayData, 'warnings' => $warnings];
    }

    private function mapFieldValue(mixed $value, \Statamic\Fields\Field $field, array &$warnings, string $siteHandle): mixed
    {
        $type = $field->type();

        if (in_array($type, self::GEN_GROUP_TYPES)) {
            if (! is_array($value)) {
                return null;
            }

            $out = [];

            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $sh = $sub->handle();

                if (! array_key_exists($sh, $value)) {
                    continue;
                }

                $mapped = $this->mapFieldValue($value[$sh], $sub, $warnings, $siteHandle);

                if ($mapped !== null) {
                    $out[$sh] = $mapped;
                }
            }

            return $out;
        }

        if (in_array($type, self::GEN_LINK_TYPES)) {
            return $this->mapLinkFieldValue($value, $field, $warnings);
        }

        if ($type === 'video') {
            return $this->mapVideoFieldValue($value, $warnings);
        }

        if (in_array($type, self::GEN_TEXT_TYPES)) {
            if (! is_string($value) && ! is_scalar($value)) {
                $this->warnNonScalarDrop($field, $warnings);
                return null;
            }
            $text = is_string($value) ? $value : (string) $value;

            return strip_tags($this->aiService->cleanResult($text));
        }

        if (in_array($type, self::GEN_HTML_TYPES)) {
            if (! is_string($value) && ! is_scalar($value)) {
                $this->warnNonScalarDrop($field, $warnings);
                return null;
            }
            $html = is_string($value) ? $value : (string) $value;

            $nodes = $this->htmlToFullBardDocument($html);
            $buttons = $field->config()['buttons'] ?? null;

            return $this->sanitizeBardNodesForFieldButtons(
                $nodes,
                is_array($buttons) ? $buttons : null,
            );
        }

        if (in_array($type, self::GEN_CHOICE_TYPES)) {
            $options = $field->config()['options'] ?? [];
            $validValues = [];

            if (is_array($options)) {
                foreach ($options as $key => $opt) {
                    if (is_array($opt)) {
                        $validValues[] = $opt['value'] ?? $opt['key'] ?? (string) $key;
                    } else {
                        $validValues[] = (string) $key;
                    }
                }
            }

            if (! is_scalar($value)) {
                $this->warnNonScalarDrop($field, $warnings);
                return null;
            }
            $strValue = (string) $value;

            if (! empty($validValues) && ! in_array($strValue, $validValues)) {
                $warnings[] = __(':field: AI selected ":value" which is not a valid option. Field left empty.', [
                    'field' => $field->display(),
                    'value' => $strValue,
                ]);

                return null;
            }

            return $strValue;
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (in_array($type, self::GEN_DATE_TYPES)) {
            if (! is_scalar($value)) {
                $this->warnNonScalarDrop($field, $warnings);
                return null;
            }
            $strValue = (string) $value;
            $date = \DateTime::createFromFormat('Y-m-d', $strValue);

            if (! $date || $date->format('Y-m-d') !== $strValue) {
                $warnings[] = __(':field: AI provided invalid date ":value". Field left empty.', [
                    'field' => $field->display(),
                    'value' => $strValue,
                ]);

                return null;
            }

            return $strValue;
        }

        if ($type === 'terms') {
            return $this->mapTermsFieldValue($value, $field, $warnings, $siteHandle);
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES)) {
            if (! is_array($value)) {
                return null;
            }

            return $this->mapReplicatorData($value, $field, $warnings, $siteHandle);
        }

        return null;
    }

    /**
     * When the LLM echoes an existing block/row `id` (the update-mode snapshot
     * shows current blocks as raw JSON including ids), keep it instead of
     * generating a fresh UUID. Stable ids keep Statamic revision diffs and the
     * CP UI honest about which blocks actually changed during an update.
     *
     * @param  array<string, mixed>  $set
     */
    private function reusableBlockId(array $set): ?string
    {
        $id = $set['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        $id = trim($id);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $id) === 1 ? $id : null;
    }

    /**
     * @return array<mixed>
     */
    private function mapReplicatorData(array $sets, \Statamic\Fields\Field $field, array &$warnings, string $siteHandle): array
    {
        $result = [];
        $fieldtype = $field->fieldtype();

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            if ($field->type() === 'grid') {
                // Grid rows don't have a "type" key
                $gridFields = $fieldtype->fields();
                $row = ['id' => $this->reusableBlockId($set) ?? Str::uuid()->toString()];

                foreach ($gridFields->all() as $subField) {
                    $subHandle = $subField->handle();

                    if (array_key_exists($subHandle, $set)) {
                        $mapped = $this->mapFieldValue($set[$subHandle], $subField, $warnings, $siteHandle);

                        if ($mapped !== null) {
                            $row[$subHandle] = $mapped;
                        }
                    }
                }

                $result[] = $row;
            } else {
                // Replicator/components — sets have a "type" key
                $setType = $set['type'] ?? null;

                if (! $setType) {
                    continue;
                }

                // Cross-blueprint safety: when a new entry is modelled on another
                // entry's layout (read_entry_structure), the LLM may emit a set
                // handle that does not exist in THIS field. Skip it with a warning
                // rather than persisting an empty, invalid block.
                if (! collect($fieldtype->flattenedSetsConfig())->has($setType)) {
                    $warnings[] = __('Unknown set type ":type" in :field. Skipped.', [
                        'type' => $setType,
                        'field' => $field->display(),
                    ]);

                    continue;
                }

                $mappedSet = [
                    'id' => $this->reusableBlockId($set) ?? Str::uuid()->toString(),
                    'type' => $setType,
                    'enabled' => true,
                ];

                try {
                    $setFields = $fieldtype->fields($setType);
                } catch (\Exception) {
                    $warnings[] = __('Unknown set type ":type" in :field. Skipped.', [
                        'type' => $setType,
                        'field' => $field->display(),
                    ]);

                    continue;
                }

                foreach ($setFields->all() as $subField) {
                    $subHandle = $subField->handle();

                    if (array_key_exists($subHandle, $set)) {
                        $mapped = $this->mapFieldValue($set[$subHandle], $subField, $warnings, $siteHandle);

                        if ($mapped !== null) {
                            $mappedSet[$subHandle] = $mapped;
                        }
                    }
                }

                $result[] = $mappedSet;
            }
        }

        return $result;
    }

    private function mapLinkFieldValue(mixed $value, \Statamic\Fields\Field $field, array &$warnings): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            $this->warnNonScalarDrop($field, $warnings);
            return null;
        }
        $str = is_string($value) ? trim($value) : (string) ($value ?? '');

        if ($str === '') {
            return null;
        }

        if ($str === '/') {
            return '/';
        }

        if (preg_match('/^entry::[0-9a-f-]{36}$/i', $str)) {
            return $str;
        }

        if (str_starts_with($str, 'asset::')) {
            return $str;
        }

        if ($str === '@child') {
            return $str;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (str_starts_with($str, '/') && strlen($str) > 1) {
            return $str;
        }

        $warnings[] = __(':field: link value ":value" is not a valid URL or Statamic link reference.', [
            'field' => $field->display(),
            'value' => $str,
        ]);

        return null;
    }

    private function mapVideoFieldValue(mixed $value, array &$warnings): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }
        $str = is_string($value) ? trim($value) : (string) ($value ?? '');

        if ($str === '') {
            return null;
        }

        if (filter_var($str, FILTER_VALIDATE_URL)) {
            return $str;
        }

        if (preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be)\S+#i', $str)) {
            return $str;
        }

        $warnings[] = __('Invalid video URL: :value', ['value' => $str]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyMandatoryFieldFallbacks(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, string $prompt, string $siteHandle): void
    {
        foreach ($blueprint->fields()->all() as $field) {
            $this->applyMandatoryFieldFallbackForField($field, $data, $displayData, $warnings, $prompt, $siteHandle);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     * @param  array<string, string>  $warnings
     */
    private function applyMandatoryFieldFallbackForField(Field $field, array &$data, array &$displayData, array &$warnings, string $prompt, string $siteHandle): void
    {
        $type = $field->type();
        $handle = $field->handle();

        if ($type === 'section' || in_array($type, self::GEN_SKIP_TYPES, true)) {
            return;
        }

        if (in_array($type, self::GEN_GROUP_TYPES, true)) {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                if (! $field->isRequired()) {
                    return;
                }
                $data[$handle] = [];
                $displayData[$handle] = [];
            }
            foreach ($field->fieldtype()->fields()->all() as $sub) {
                $this->applyMandatoryFieldFallbackForField($sub, $data[$handle], $displayData[$handle], $warnings, $prompt, $siteHandle);
            }

            return;
        }

        if (! $field->isRequired()) {
            return;
        }

        if (! $this->generatableFieldMissingOrEmpty($field, $data, $handle)) {
            return;
        }

        if (in_array($type, self::GEN_TEXT_TYPES, true)) {
            $text = $this->syntheticTextForRequiredField($field, $prompt);
            $cleaned = strip_tags($this->aiService->cleanResult($text));
            $data[$handle] = $cleaned;
            $displayData[$handle] = $cleaned;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_HTML_TYPES, true)) {
            $html = '<p>'.e($this->syntheticTextForRequiredField($field, $prompt)).'</p>';
            $mapped = $this->mapFieldValue($html, $field, $warnings, $siteHandle);

            if ($mapped !== null) {
                $data[$handle] = $mapped;
                $displayData[$handle] = $html;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if ($type === 'terms') {
            $fallback = $this->firstTermValueForTermsField($field, $siteHandle);

            if ($fallback !== null) {
                $data[$handle] = $fallback;
                $displayData[$handle] = $fallback;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if (in_array($type, self::GEN_CHOICE_TYPES, true)) {
            $first = $this->firstSelectOptionValue($field);

            if ($first !== null && $first !== '') {
                $data[$handle] = $first;
                $displayData[$handle] = $first;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if (in_array($type, self::GEN_BOOLEAN_TYPES, true)) {
            $data[$handle] = false;
            $displayData[$handle] = false;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_DATE_TYPES, true)) {
            $d = now()->format('Y-m-d');
            $data[$handle] = $d;
            $displayData[$handle] = $d;
            $this->requiredWasAutofilledWarning($field, $warnings);

            return;
        }

        if (in_array($type, self::GEN_LINK_TYPES, true)) {
            $mapped = $this->mapLinkFieldValue('/', $field, $warnings);

            if ($mapped !== null) {
                $data[$handle] = $mapped;
                $displayData[$handle] = $mapped;
                $this->requiredWasAutofilledWarning($field, $warnings);
            }

            return;
        }

        if ($type === 'video') {
            $warnings[] = __(':field is required but no video URL was provided. Add a URL in the editor after saving.', [
                'field' => $field->display(),
            ]);

            return;
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES, true)) {
            $warnings[] = __(':field is required but has no blocks. Add content in the editor after saving.', [
                'field' => $field->display(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generatableFieldMissingOrEmpty(Field $field, array $data, string $handle): bool
    {
        if (! array_key_exists($handle, $data)) {
            return true;
        }

        $value = $data[$handle];
        $type = $field->type();

        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (in_array($type, self::GEN_TEXT_TYPES, true) && is_string($value) && trim($value) === '') {
            return true;
        }

        if (in_array($type, self::GEN_HTML_TYPES, true)) {
            return $this->isBardStoredValueEmpty($value);
        }

        if (in_array($type, self::GEN_RECURSIVE_TYPES, true)) {
            return ! is_array($value) || $value === [];
        }

        if ($type === 'terms') {
            if ($value === null || $value === [] || $value === '') {
                return true;
            }

            if (is_string($value) && trim($value) === '') {
                return true;
            }

            return false;
        }

        return false;
    }

    private function isBardStoredValueEmpty(mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        if (($value['type'] ?? '') === 'doc') {
            $content = $value['content'] ?? [];

            return $content === [] || $content === null;
        }

        return false;
    }

    private function syntheticTextForRequiredField(Field $field, string $prompt): string
    {
        $p = trim($prompt);

        if ($p === '' || preg_match('/^(do nothing|nothing|nichts|mach nichts|tu rien|ne rien faire)\.?$/iu', $p)) {
            $label = $field->display();

            return $label !== '' ? $label : (string) __('Untitled entry');
        }

        $limit = $field->handle() === 'title' ? 100 : 240;

        return Str::limit($p, $limit);
    }

    private function firstSelectOptionValue(Field $field): ?string
    {
        $options = $field->config()['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return null;
        }

        foreach ($options as $key => $opt) {
            if (is_array($opt)) {
                $v = $opt['value'] ?? $opt['key'] ?? null;

                if ($v !== null && $v !== '') {
                    return (string) $v;
                }
            } elseif (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $warnings
     */
    private function requiredWasAutofilledWarning(Field $field, array &$warnings): void
    {
        $warnings[] = __(':field was missing or empty but is required; a safe default was applied.', [
            'field' => $field->display(),
        ]);
    }

    /**
     * Surfaced when the AI returns a non-scalar (e.g. an array) for a field that expects
     * a scalar value. The field is dropped to avoid casting errors; the user is told why.
     */
    private function warnNonScalarDrop(Field $field, array &$warnings): void
    {
        $warnings[] = __(':field: AI returned an unexpected value shape and was skipped. Try again or rephrase the prompt.', [
            'field' => $field->display(),
        ]);
    }

    // ---------------------------------------------------------------------
    //  Update path (BOLD agent update_entry_job tool).
    //  Kept structurally separate from the create path so it can be removed,
    //  swapped, or replaced without touching generateContent / createEntry.
    // ---------------------------------------------------------------------

    /**
     * Generate a partial-update payload for an existing entry. Returns only the
     * fields the LLM chose to change. The system prompt embeds the current
     * values so the model can decide what to keep, what to rewrite, and what to leave alone.
     *
     * @param  callable(string): void|null  $onStreamToken
     * @param  array{appendix: string, warnings: array<int, string>, preferred: \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths, appended_to_prompts?: bool}|null  $prefetchedUrlAug
     * @param  callable(): void|null  $streamHeartbeat
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[], collection: string, blueprint: string}
     */
    public function generateUpdateForEntry(
        string $entryId,
        string $prompt,
        string $locale,
        ?string $attachmentContent = null,
        ?callable $onStreamToken = null,
        ?array $prefetchedUrlAug = null,
        ?callable $streamHeartbeat = null,
    ): array {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new \RuntimeException(__('Entry not found.'));
        }

        $collectionHandle = (string) $entry->collectionHandle();
        $blueprint = $entry->blueprint();

        if (! $blueprint) {
            throw new \RuntimeException(__('Blueprint not found for entry.'));
        }

        $blueprintHandle = (string) $blueprint->handle();
        $fieldSchema = $this->buildFieldSchema($blueprint, $locale);
        // Update mode: surface asset fields the create path hides (`GEN_SKIP_TYPES`)
        // so the LLM can ask for a replacement. We walk into group fields too — many
        // blueprints place their hero/main image inside a group like `image_or_video`.
        // Returns a list of {field, path} pairs the resolver step uses below.
        $assetFields = $this->augmentSchemaWithAssetFields($fieldSchema, $blueprint);

        $snapshotIncompleteFields = [];
        $currentSnapshot = $this->buildCurrentEntrySnapshot($entry, $blueprint, $snapshotIncompleteFields);
        $systemMessage = $this->buildUpdateSystemMessage($fieldSchema, $locale, $currentSnapshot);

        $useUrlTool = (bool) config('statamic-ai-assistant.entry_generator_fetch_url_tool', true)
            && (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)
            && $this->aiService->supportsChatTools();

        if ($prefetchedUrlAug !== null) {
            $urlAug = [
                'appendix' => $prefetchedUrlAug['appendix'],
                'warnings' => $prefetchedUrlAug['warnings'],
            ];
            $combinedAppendix = (bool) ($prefetchedUrlAug['appended_to_prompts'] ?? false)
                ? ''
                : (string) $prefetchedUrlAug['appendix'];
        } elseif ($useUrlTool) {
            $urlAug = ['appendix' => '', 'warnings' => []];
            $combinedAppendix = '';
        } else {
            $urlAug = $this->promptUrlFetcher->buildAugmentation($prompt);
            $combinedAppendix = (string) $urlAug['appendix'];
        }

        $userMessage = $this->buildUserMessage($prompt, $attachmentContent, $combinedAppendix);

        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $maxTokens = (int) config('statamic-ai-assistant.generator_max_tokens', 4000);
        $toolWarnings = [];

        if ($useUrlTool) {
            try {
                $rawResponse = $this->generateContentWithUrlFetchToolLoop(
                    $messages,
                    $maxTokens,
                    $onStreamToken,
                    $toolWarnings,
                    new \BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths,
                    null,
                    false,
                    $streamHeartbeat,
                );
            } catch (\Throwable $e) {
                Log::warning('[entry-update] tool loop failed; falling back to single-shot completion', [
                    'message' => $e->getMessage(),
                ]);
                $rawResponse = $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);
            }
        } else {
            $rawResponse = $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);
        }

        if ($rawResponse === null || $rawResponse === '') {
            throw new \RuntimeException(__('The AI returned no content. Check your provider settings and try again.'));
        }

        $parsedData = $this->parseResponse($rawResponse);
        $result = $this->mapToFieldData($parsedData, $blueprint, $locale, $prompt, null, true);

        // Resolve asset fields (top-level + nested in groups) the LLM asked to
        // replace. Fields the LLM did NOT mention stay absent from $data, so
        // the merge in updateEntryFromData preserves the existing image.
        $this->resolveUpdateAssetReplacements($result, $entry, $assetFields, $parsedData);

        // Data-loss guard: list fields (replicator/grid) are replaced wholesale on
        // update. When the snapshot had to truncate or omit such a field, the LLM
        // never saw its full content — a returned replacement may silently drop
        // blocks. Surface that as a review warning instead of failing the update.
        foreach ($snapshotIncompleteFields as $incompleteHandle) {
            if (array_key_exists($incompleteHandle, $result['data'] ?? [])) {
                $result['warnings'][] = __(
                    'The field ":field" was too large to show the AI in full, but the AI returned a replacement for it. Review that field carefully before publishing — blocks the AI never saw may be missing.',
                    ['field' => $incompleteHandle]
                );
            }
        }

        foreach ($urlAug['warnings'] as $w) {
            $result['warnings'][] = $w;
        }
        foreach ($toolWarnings as $w) {
            $result['warnings'][] = $w;
        }

        $result['collection'] = $collectionHandle;
        $result['blueprint'] = $blueprintHandle;

        return $result;
    }

    /**
     * Inject top-level `assets` fields (skipped by the create-mode schema) as
     * `asset_description` entries so the update LLM has a handle to write to.
     * Returns the list of injected handles for the resolver step.
     *
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @return array<int, string>
     */
    /**
     * Walk the blueprint recursively (top level + groups) and inject
     * asset_description schema entries with `available_assets` for every assets
     * field at any nesting depth. Returns one {field, path} pair per asset
     * field so the resolver step knows where to write the picked path.
     *
     * Replicator/components/grid are intentionally NOT walked here: their dynamic
     * sets are already exposed via the (inside-sets) asset_description rule in
     * buildSystemMessage, and resolving inside dynamic blocks needs different
     * indexing. That can be a follow-up if needed.
     *
     * @return array<int, array{field: \Statamic\Fields\Field, path: array<int, string>}>
     */
    private function augmentSchemaWithAssetFields(array &$fieldSchema, Blueprint $blueprint): array
    {
        $assetFields = [];
        $listingCap = max(0, (int) config('statamic-ai-assistant.bold_agent_asset_listing_cap', 100));

        $this->walkAndInjectAssets(
            $blueprint->fields()->all(),
            $fieldSchema,
            [],
            $listingCap,
            $assetFields,
        );

        return $assetFields;
    }

    /**
     * @param  iterable<int, \Statamic\Fields\Field>  $fields  Array or Statamic Fields collection
     * @param  array<int, string>  $path
     * @param  array<int, array{field: \Statamic\Fields\Field, path: array<int, string>}>  $assetFields
     */
    private function walkAndInjectAssets(iterable $fields, array &$schemaSlice, array $path, int $listingCap, array &$assetFields): void
    {
        foreach ($fields as $field) {
            $handle = $field->handle();
            $type = $field->type();
            $thisPath = array_merge($path, [$handle]);

            if ($type === 'assets') {
                $availablePaths = $listingCap > 0
                    ? $this->assetResolver->listFieldAssetPaths($field, $listingCap)
                    : [];
                $instructions = $field->instructions();

                $entry = [
                    'label' => $field->display(),
                    'generatable' => true,
                    'type' => 'asset_description',
                    'description' => ($instructions ? $instructions.' ' : '')
                        .'OMIT this key entirely to keep the current asset. '
                        .'To replace the image, return EITHER (a) one exact path from `available_assets` below '
                        .'(preferred — lets you respect user constraints like "not from the X folder"), '
                        .'OR (b) a short description if nothing in the list fits, in which case the system picks a fitting asset randomly. '
                        .'Paths are relative to the field\'s asset container; do not prefix with a slash.',
                    'available_assets' => $availablePaths,
                ];

                if ($listingCap > 0 && count($availablePaths) === $listingCap) {
                    $entry['available_assets_note'] = "Only the first {$listingCap} paths are listed; the container may hold more. If none of the listed paths fits, return a description and the system picks from the full set.";
                }

                $schemaSlice[$handle] = $entry;
                $assetFields[] = ['field' => $field, 'path' => $thisPath];

                Log::info('[entry-update] asset field listing', [
                    'field_path' => implode('.', $thisPath),
                    'container' => $field->config()['container'] ?? null,
                    'folder' => $field->config()['folder'] ?? null,
                    'available_assets_count' => count($availablePaths),
                    'sample' => array_slice($availablePaths, 0, 5),
                ]);

                continue;
            }

            if (in_array($type, self::GEN_GROUP_TYPES, true)) {
                // The group's schema entry already exists from buildFieldSchema
                // — we just need to ensure its `fields` slice is mutable. If
                // every sub-field was previously skipped (e.g. all were assets),
                // the group entry might be missing — synthesize a minimal one.
                if (! isset($schemaSlice[$handle]) || ! is_array($schemaSlice[$handle])) {
                    $schemaSlice[$handle] = [
                        'label' => $field->display(),
                        'generatable' => true,
                        'type' => 'group',
                        'fields' => [],
                    ];
                }
                if (! isset($schemaSlice[$handle]['fields']) || ! is_array($schemaSlice[$handle]['fields'])) {
                    $schemaSlice[$handle]['fields'] = [];
                }

                $this->walkAndInjectAssets(
                    $field->fieldtype()->fields()->all(),
                    $schemaSlice[$handle]['fields'],
                    $thisPath,
                    $listingCap,
                    $assetFields,
                );
            }
        }
    }

    /**
     * For each asset_description handle the LLM filled with a non-empty string,
     * pick a replacement asset via the existing asset resolver. Handles the LLM
     * omitted are left untouched so the merge preserves the current value.
     *
     * @param  array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}  $result
     * @param  array<int, string>  $assetHandles
     * @param  array<string, mixed>  $parsedData
     */
    /**
     * @param  array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}  $result
     * @param  array<int, array{field: \Statamic\Fields\Field, path: array<int, string>}>  $assetFields
     * @param  array<string, mixed>  $parsedData
     */
    private function resolveUpdateAssetReplacements(array &$result, StatamicEntry $entry, array $assetFields, array $parsedData): void
    {
        if ($assetFields === []) {
            return;
        }

        $entryData = is_array($entry->data()->all()) ? $entry->data()->all() : [];

        $directPicks = [];   // [pathStr => ['path' => array, 'value' => resolvedValue]]
        $randomPicks = [];   // [['field' => ..., 'path' => array]]
        $rawByPath = [];     // [pathStr => llm value] for logging

        foreach ($assetFields as $info) {
            $field = $info['field'];
            $path = $info['path'];
            $pathStr = implode('.', $path);

            $value = $this->getNestedValue($parsedData, $path);
            if ($value === null) {
                continue; // LLM omitted this field — preserve existing
            }
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $rawByPath[$pathStr] = $value;

            $candidate = ltrim(trim($value), '/');
            if ($candidate !== '' && $this->assetResolver->fieldHasAssetPath($field, $candidate)) {
                $config = $field->config();
                $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
                $resolved = $maxFiles === 1 ? $candidate : [$candidate];
                $directPicks[$pathStr] = ['path' => $path, 'value' => $resolved];
            } else {
                $randomPicks[] = ['field' => $field, 'path' => $path];
            }
        }

        Log::info('[entry-update] asset replacement resolution', [
            'direct_picks' => array_map(fn ($p) => ['path' => implode('.', $p['path']), 'value' => $p['value']], array_values($directPicks)),
            'random_picks' => array_map(fn ($p) => implode('.', $p['path']), $randomPicks),
            'llm_raw_for_assets' => $rawByPath,
        ]);

        foreach ($directPicks as $info) {
            $this->seedNestedFromExisting($result['data'], $info['path'], $entryData);
            $this->seedNestedFromExisting($result['displayData'], $info['path'], $entryData);
            $this->setNestedValue($result['data'], $info['path'], $info['value']);
            $this->setNestedValue($result['displayData'], $info['path'], $info['value']);
        }

        foreach ($randomPicks as $pick) {
            $picked = $this->assetResolver->pickReplacementForField($pick['field'], $result['warnings'], null);
            if ($picked === null || $picked === '' || $picked === []) {
                $result['warnings'][] = __(':field: no replacement image was found in the asset container; current image kept.', [
                    'field' => implode('.', $pick['path']),
                ]);
                continue;
            }
            $this->seedNestedFromExisting($result['data'], $pick['path'], $entryData);
            $this->seedNestedFromExisting($result['displayData'], $pick['path'], $entryData);
            $this->setNestedValue($result['data'], $pick['path'], $picked);
            $this->setNestedValue($result['displayData'], $pick['path'], $picked);
        }
    }

    /**
     * Read a value from $data following a path of keys; returns null if any key is missing.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     */
    private function getNestedValue(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Ensure each parent key along the path exists in $data, copying any
     * existing slice from $entryData so siblings (e.g. video_enabled in an
     * image_or_video group) survive the eventual save.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     * @param  array<string, mixed>  $entryData
     */
    private function seedNestedFromExisting(array &$data, array $path, array $entryData): void
    {
        if (count($path) <= 1) {
            return;
        }

        $cursor = &$data;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $key = $path[$i];
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $existing = $this->getNestedValue($entryData, array_slice($path, 0, $i + 1));
                $cursor[$key] = is_array($existing) ? $existing : [];
            }
            $cursor = &$cursor[$key];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     */
    private function setNestedValue(array &$data, array $path, mixed $value): void
    {
        $cursor = &$data;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $key = $path[$i];
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
        }
        $cursor[end($path)] = $value;
    }

    /**
     * Persist a partial update: load the entry, merge only the fields produced
     * by `generateUpdateForEntry`, and save. Slug, date, and published flag are
     * preserved unless the LLM explicitly returned a new title (slug stays
     * unchanged on purpose — re-slugging breaks URLs).
     */
    public function updateEntryFromData(string $entryId, array $data): StatamicEntry
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new \RuntimeException(__('Entry not found.'));
        }

        if ($data === []) {
            return $entry;
        }

        $existing = is_array($entry->data()->all()) ? $entry->data()->all() : [];
        $entry->data($this->deepMergeReplacingLists($existing, $data));
        $entry->save();

        return $entry;
    }

    /**
     * Deep-merge $b into $a where:
     *  - Associative array nodes recurse (so a group like `image_or_video`
     *    preserves siblings the LLM did not return).
     *  - List nodes (numerically-indexed) get REPLACED wholesale (so a
     *    replicator array like `page_builder` doesn't accumulate stale blocks
     *    from the existing entry when the LLM returns a shorter list).
     *  - Scalars and type mismatches are replaced.
     *
     * @param  array<string|int, mixed>  $a
     * @param  array<string|int, mixed>  $b
     * @return array<string|int, mixed>
     */
    private function deepMergeReplacingLists(array $a, array $b): array
    {
        foreach ($b as $key => $val) {
            if (is_array($val) && isset($a[$key]) && is_array($a[$key]) && ! array_is_list($val) && ! array_is_list($a[$key])) {
                $a[$key] = $this->deepMergeReplacingLists($a[$key], $val);
            } else {
                $a[$key] = $val;
            }
        }

        return $a;
    }

    /**
     * Snapshot of the entry's current values, rendered as a key-by-key text
     * block. Complex-field values are emitted as raw JSON so the LLM has the
     * data it needs to return a FULL replacement; text fields are quoted
     * strings; assets show their stored path(s). Total size is capped so
     * token usage stays predictable on entries with deep replicator trees.
     */
    /**
     * @param  array<int, string>|null  $incompleteFields  Out-list of handles the snapshot truncated/omitted.
     */
    private function buildCurrentEntrySnapshot(StatamicEntry $entry, Blueprint $blueprint, ?array &$incompleteFields = null): string
    {
        return $this->structureSerializer->serialize($entry, $blueprint, $incompleteFields);
    }

    /**
     * Update-mode system prompt: same field schema rules as creation, but the
     * model is told to omit unchanged fields and is shown a snapshot of current values.
     *
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @param  array<string, mixed>  $currentSnapshot
     */
    private function buildUpdateSystemMessage(array $fieldSchema, string $locale, string $currentSnapshot): string
    {
        $base = $this->buildSystemMessage($fieldSchema, $locale);

        // Highest-priority block: this comes BEFORE the field-schema rules so
        // the LLM anchors on update semantics (omit-to-keep, full-replace,
        // path-picking) rather than the create-mode wording in $base.
        $priority = "\n\n========== UPDATE MODE — read this BEFORE the schema rules above ==========\n"
            ."You are editing an EXISTING entry. Different rules apply than creation:\n\n"
            ."1) OMIT KEYS YOU DO NOT CHANGE.\n"
            ."   - Return ONLY the keys you want to change. Keys you omit are kept as-is.\n"
            ."   - Do NOT echo unchanged fields back; do NOT invent placeholder values; do NOT re-fill required fields the user did not ask about.\n"
            ."   - Never use null or empty string to clear a field — omitting it is the only way to leave it unchanged.\n\n"
            ."2) ASSET FIELDS (type \"asset_description\" in the schema, with an `available_assets` array):\n"
            ."   - This rule OVERRIDES any earlier wording about asset_description being \"a concise phrase describing the desired image\".\n"
            ."   - To KEEP the current image: omit the key entirely.\n"
            ."   - To REPLACE the image: return ONE exact path string copied from that field's `available_assets` array — nothing else.\n"
            ."   - When the user excludes a folder/path (e.g. \"don't use anything from the set previews folder\"), simply SKIP every path in `available_assets` whose prefix matches that exclusion and pick from the remaining paths.\n"
            ."   - When the user asks for variety (\"use another image\", \"different from before\"), avoid the path that is currently set (shown in the snapshot below) and pick a DIFFERENT one from `available_assets`.\n"
            ."   - Only when no path in `available_assets` fits the user's intent: return a short free-form description instead, and the system picks a random fitting asset (this is the LAST resort and will NOT respect path-based exclusions).\n\n"
            ."3) COMPLEX FIELDS (Bard html, structured/replicator/components/grid, group):\n"
            ."   - The snapshot below shows their current FULL content as raw JSON.\n"
            ."   - If you change one, you MUST return the COMPLETE new value with your edits applied — what you return REPLACES the entire field. Returning a partial value will lose data.\n\n"
            ."4) The required-field rule from creation does NOT apply here — the entry already validates.\n"
            ."============================================================================\n";

        $tail = "\n\nCurrent values for this entry (for context — do not echo back unchanged):\n"
            .$currentSnapshot;

        return $priority.$base.$tail;
    }

    private function createEntry(
        string $collectionHandle,
        string $blueprintHandle,
        string $locale,
        array $data,
    ): StatamicEntry {
        $collection = Collection::findByHandle($collectionHandle);

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->blueprint($blueprintHandle)
            ->locale($locale);

        $title = $data['title'] ?? null;
        $slug = is_string($title) && $title !== ''
            ? Str::slug($title)
            : 'untitled-'.now()->timestamp;

        $entry->slug($slug);
        $entry->data($data);

        // Dated collections use a separate entry date (ordering, CP sidebar, filenames).
        // Blueprint `date` in $data alone does not always set that attribute — the CP can
        // still show "today" unless we assign it explicitly.
        if ($collection && $collection->dated()) {
            $entryDate = $this->carbonFromBlueprintDateData($data['date'] ?? null);
            if ($entryDate !== null) {
                $entry->date($entryDate);
            }
        }

        $entry->published(false);
        $entry->save();

        return $entry;
    }

    /**
     * Parse a Statamic date field value (Y-m-d string) for use as the dated entry's order date.
     */
    private function carbonFromBlueprintDateData(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        try {
            $c = Carbon::createFromFormat('Y-m-d', $raw);
        } catch (\Throwable) {
            return null;
        }

        if (! $c || $c->format('Y-m-d') !== $raw) {
            return null;
        }

        return $c->startOfDay();
    }
}
