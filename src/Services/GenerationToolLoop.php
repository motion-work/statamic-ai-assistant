<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Semantic\SemanticEntrySearch;
use MotionWork\StatamicAiAssistant\Services\Ai\GeneratesImages;
use MotionWork\StatamicAiAssistant\Support\AssistantToolCallMessage;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
use MotionWork\StatamicAiAssistant\Support\WebSearch;
use MotionWork\StatamicAiAssistant\Tools\AnalyzeImageTool;
use MotionWork\StatamicAiAssistant\Tools\ChatTool;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use MotionWork\StatamicAiAssistant\Tools\GenerateImageTool;
use MotionWork\StatamicAiAssistant\Tools\ListAssetsTool;
use MotionWork\StatamicAiAssistant\Tools\ListTaxonomiesTool;
use MotionWork\StatamicAiAssistant\Tools\ReadDocumentTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryStructureTool;
use MotionWork\StatamicAiAssistant\Tools\ReadEntryTool;
use MotionWork\StatamicAiAssistant\Tools\ReadGlobalsTool;
use MotionWork\StatamicAiAssistant\Tools\ReadNavTreeTool;
use MotionWork\StatamicAiAssistant\Tools\SaveImageTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UrlFetchTool;
use MotionWork\StatamicAiAssistant\Tools\UseAssetsTool;

/**
 * The agent's multi-turn generation: the model may fetch the URLs the user
 * gave, save images from them, and read CMS context (entries, globals, nav,
 * taxonomies, assets) before it answers with the entry's JSON.
 */
final class GenerationToolLoop
{
    public function __construct(
        private AbstractAiService $aiService,
        private PromptUrlFetcher $promptUrlFetcher,
        private ?RemoteImageFetcher $imageFetcher,
        private EntryStructureSerializer $structureSerializer,
        private ?EntrySearch $search = null,
    ) {
        // Entry discovery for the read tools: keyword search plus semantic
        // matches, falling back to the keyword search alone on its own.
        $this->search ??= new SemanticEntrySearch;
    }

    /**
     * Runs the rounds and returns the model's final text.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $toolWarningsOut
     * @param  string|null  $imageContainerHint  Container that receives saved images; null offers no image tool
     * @param  bool  $disableUrlFetch  An attached document is the source, so nothing is fetched
     */
    public function run(
        array $messages,
        int $maxTokens,
        ?callable $onStreamToken,
        array &$toolWarningsOut,
        PreferredAssetPaths $imageSink,
        ?string $imageContainerHint,
        bool $disableUrlFetch = false,
    ): string {
        // The image tool only makes sense on the create path, where empty asset
        // fields are auto-filled from the preferred-asset queue afterwards. On
        // the update path the resolver is skipped, so a saved image would upload
        // but never attach — that path passes no container hint. A null hint also
        // means the blueprint has no asset field to receive images, so saving them
        // would just orphan files.
        $useImageTool = $this->imageFetcher !== null
            && $imageContainerHint !== null
            && (bool) config('statamic-ai-assistant.image_fetch.enabled', true);

        $working = $messages;
        // Prepend the URL rules. The downstream system message contains the field
        // schema (often >40k chars) followed by 'respond with ONLY a JSON object',
        // and putting the tool rules at the end leaves them buried where smaller
        // models miss them.
        $working[0]['content'] = $this->toolRules($useImageTool, $disableUrlFetch)."\n".($working[0]['content'] ?? '');

        // Web search (switched on per message by the editor; the entry job runs
        // inside WebSearch::during()). Never alongside an attachment, which is
        // the source by itself. The rules go last so the cached prefix holds.
        $webSearch = WebSearch::activeInScope() && ! $disableUrlFetch;
        if ($webSearch) {
            $working[0]['content'] .= "\n\n".AgenticPlannerPrompt::webSearchRules()
                .'This overrides URL rule 0 for pages the search results cited: those may be fetched too.';
        }
        if ($this->offersImageGeneration()) {
            $working[0]['content'] .= "\n\n".self::imageGenerationRules();
        }

        // Shared tool runner: one dispatch + per-tool call budgets for both this
        // loop and the planner. Read tools (fetch/image/read-entry) implement the
        // ChatTool contract; the runner owns the tool_call → tool-reply plumbing.
        $toolContext = new ToolContext(
            warningSink: function (string $w) use (&$toolWarningsOut) {
                $toolWarningsOut[] = $w;
            },
            onStreamToken: $onStreamToken,
            imageSink: $imageSink,
            imageContainerHint: $imageContainerHint,
        );
        $runner = new ChatToolRunner($this->toolset($messages, $useImageTool, $disableUrlFetch), $toolContext);

        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $promptHasUrl = ! $disableUrlFetch && $this->promptHasUrl($messages);

        for ($round = 0; $round < $maxRounds; $round++) {
            $forceTool = $promptHasUrl && $runner->callCount('fetch_page_content') === 0;
            // Use the explicit named-tool form on the first round when a URL is
            // present. Some OpenAI-compatible providers (notably Infomaniak's
            // Mistral deployments) silently ignore tool_choice: 'required' but
            // honor a specific {type:function, function:{name:...}} directive.
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            // Drop stale bulky tool results from the conversation before paying
            // for them again (see ChatToolRunner::compactToolMessages).
            ChatToolRunner::compactToolMessages($working);

            [$data, $msg] = $this->complete($working, $maxTokens, $runner, $toolChoice, $webSearch);

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                $this->logIgnoredForcedTool($round, $toolChoice, $data);
            }

            if ($hasToolCalls) {
                $working[] = AssistantToolCallMessage::from($msg, $toolCalls);

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
     * One completion; the assistant message, or an exception for a malformed reply.
     *
     * @param  array<int, array<string, mixed>>  $working
     * @param  string|array<string, mixed>  $toolChoice
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function complete(array $working, int $maxTokens, ChatToolRunner $runner, string|array $toolChoice, bool $webSearch = false): array
    {
        $tools = $runner->definitions();
        if ($webSearch) {
            // OpenRouter runs the search server-side: listed, never dispatched.
            $tools[] = WebSearch::serverTool();
        }

        $data = $this->aiService->createChatCompletion($working, $maxTokens, $tools, $toolChoice);
        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice)) {
            throw new \RuntimeException(__('Unexpected AI response shape.'));
        }

        $msg = $choice['message'] ?? null;

        if (! is_array($msg)) {
            throw new \RuntimeException(__('Unexpected AI response shape.'));
        }

        if ($webSearch) {
            $msg = WebSearch::withoutCitationMarkers($msg);
            $citations = WebSearch::citationsFrom($msg);
            $runner->context()->rememberCitations($citations);
            WebSearch::reportCitations($citations);
        }

        return [$data, $msg];
    }

    /**
     * We forced the tool and the model still didn't call it: dump the raw
     * response so we can see whether the provider returned an error/warning
     * field, an unexpected message shape, or stripped the tool_choice silently.
     *
     * @param  string|array<string, mixed>  $toolChoice
     * @param  array<string, mixed>  $data
     */
    private function logIgnoredForcedTool(int $round, string|array $toolChoice, array $data): void
    {
        Log::warning('[entry-gen-tool] forced tool call was IGNORED by model/provider', [
            'round' => $round,
            'tool_choice_sent' => $toolChoice,
            'raw_response' => Str::limit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 2000),
        ]);
    }

    private function toolRules(bool $useImageTool, bool $disableUrlFetch): string
    {
        $imageRule = $useImageTool
            ? "6. To keep the source page's imagery, call **save_remote_image** for each image you want on the entry, passing its absolute **url** (from the fetched content) and a short **reason**. Save the hero/lead image first. Saved images are attached to the entry's image fields automatically — never place image URLs in text or rich-text fields.\n"
            : '';

        return "URL HANDLING RULES — these take priority over any later JSON output rule:\n"
            ."0. Only ever fetch URLs the user explicitly provided in their message (or same-site links found INSIDE those fetched pages). NEVER invent, guess, shorten, or try alternative/variant URLs — a fetch of a guessed URL is a mistake and will be refused. If the user provided NO URL, do not fetch anything: write the entry from the CMS context and your own knowledge.\n"
            .($disableUrlFetch
                ? "ATTACHMENT RULE (overrides the URL rules above): the user attached a document and its full content is already in your user message under \"--- ATTACHED DOCUMENT CONTENT ---\". That content IS the source — do NOT call fetch_page_content at all, and do NOT use URLs found inside the attachment to go online. Write the entry entirely from the attached document's facts, structure, names, numbers and wording.\n"
                : '')
            ."1. When the user DID provide one or more http(s) URLs, you MUST call the **fetch_page_content** tool to retrieve them BEFORE producing any output. Never invent or guess content from a URL alone.\n"
            ."2. After fetching, inspect the returned content. If it is a listing or index page (multiple teasers, news cards, 'read more' links, item summaries with detail URLs) on the SAME site, identify the specific item the user asked for and call **fetch_page_content** AGAIN on that item's detail page URL. Write the entry from the detail page body, not from the listing teaser.\n"
            ."3. If the user asked for 'the first', 'the latest', 'the next', 'the second', or a specific item from a listing, pick the URL that matches that intent and fetch it.\n"
            ."4. You may call the tool multiple times. Pass **url** (full link, https preferred) and **reason** (short: which item or which blueprint field this supports).\n"
            ."5. ONLY after you have the full source text you need, respond with the JSON object for the entry fields — no markdown fences, no commentary — exactly as required by the rules below.\n"
            .$imageRule
            ."- TITLE FROM SOURCE: when you copy a page's content, set the entry's title field to that page's main heading VERBATIM. Use the fetched result's **h1** value (or the \"Page title (H1)\" line / the first top-level \"# \" heading in the fetched content). Do NOT paraphrase, translate, shorten, or invent a new title. Only deviate when the user's prompt explicitly dictates a different title, or when the source page genuinely has no H1 (then derive a concise, faithful title from the content).\n"
            ."- CONTENT FROM SOURCE: when the user asks to copy a page or base the entry on it, reproduce the fetched content FAITHFULLY and COMPLETELY — keep the same sections, headings, wording, facts, numbers and order. Do NOT summarize, shorten, omit, reorder, translate, or invent content. Map ALL of the source content across the entry's fields; when a piece of content has no dedicated field, put it in the main rich-text/body field rather than dropping it. Prefer the source's own wording over a rephrasing. Only condense if the user explicitly asked for a summary or shorter version.\n"
            ."- If the user asks to base this entry on the layout/components of an existing entry, call **read_entry_structure** (pass its **entry_id**, or a title/slug **query**) to read that entry's sections in order, then reproduce the same structure. The reference entry may use a different blueprint: map each section onto a set/field that exists in THIS entry's schema below — never copy set handles that are not in this schema.\n"
            ."- You may also read CMS context to fill fields with real data: **read_globals** for site-wide contact details / CTA links, **read_nav_tree** for internal link targets and site structure, and **list_taxonomies** to get valid term slugs before setting any `terms` field (never invent term slugs).\n"
            ."---\n";

    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, ChatTool>
     */
    private function toolset(array $messages, bool $useImageTool, bool $disableUrlFetch): array
    {
        // Only let the model open URLs the user actually provided (host allowlist
        // from the prompt) — never URLs it invents. Empty allowlist = no fetching.
        $restrictFetch = (bool) config('statamic-ai-assistant.entry_generator_restrict_fetch_to_prompt_urls', true);
        $allowedFetchHosts = UrlFetchTool::hostsFromMessages($this->promptUrlFetcher, $messages);

        // When the user attached a document, its content is already in the user
        // message — don't offer fetch_page_content at all, so the model can't go
        // online instead of using the file (and URLs inside the file can't get
        // allowlisted). Read/structure/taxonomy tools remain available.
        $toolset = [];
        if (! $disableUrlFetch) {
            $toolset[] = new UrlFetchTool($this->promptUrlFetcher, $allowedFetchHosts, $restrictFetch);
        }
        if ($useImageTool && $this->imageFetcher !== null) {
            // Same provenance gate as fetch: only save images from hosts the user
            // referenced or URLs seen in fetched pages — never invented ones.
            $toolset[] = new SaveImageTool($this->imageFetcher, $allowedFetchHosts, $restrictFetch);
        }
        $toolset[] = new ReadEntryStructureTool(
            $this->structureSerializer,
            fn (?string $collection, string $query, int $limit) => $this->search->findEntriesShortlist($collection, $query, $limit),
        );
        $toolset[] = new ReadEntryTool(
            fn (?string $collection, string $query, int $limit) => $this->search->findEntriesShortlist($collection, $query, $limit),
        );
        $toolset[] = new ReadGlobalsTool;
        $toolset[] = new ReadNavTreeTool;
        $toolset[] = new ListTaxonomiesTool;
        $toolset[] = new ListAssetsTool;
        $toolset[] = new UseAssetsTool;
        $toolset[] = new ReadDocumentTool;
        if ($this->aiService->supportsVision()) {
            $toolset[] = new AnalyzeImageTool($this->aiService);
        }
        if ($this->offersImageGeneration() && $this->aiService instanceof GeneratesImages) {
            $toolset[] = new GenerateImageTool($this->aiService, app(GeneratedImageStore::class));
        }

        return $toolset;
    }

    /** generate_image is offered inside an ImageGeneration scope (switch on, OpenRouter). */
    private function offersImageGeneration(): bool
    {
        return $this->aiService instanceof GeneratesImages && ImageGeneration::scope() !== null;
    }

    /**
     * When the editor may have images generated. Appended after the stable
     * prompt prefix, like the web search rules, so the cached part holds.
     */
    public static function imageGenerationRules(): string
    {
        return "IMAGE GENERATION (generate_image):\n"
            ."- Call generate_image ONLY when the user explicitly asks you to generate or create an image (e.g. \"erstelle ein Hero-Bild\", \"generate a picture of …\"). Never call it to fill empty image fields on your own.\n"
            ."- Prefer images the user provided and existing assets (list_assets / use_assets) over generating.\n"
            ."- Never depict a real, identifiable person, a brand logo or a copyrighted character.\n"
            .'- One call per image the user asked for. When updating an entry, set the asset field you change to the exact `path` the tool returns.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function promptHasUrl(array $messages): bool
    {
        // Detect URLs in the user message(s). If any are present, the model MUST
        // call the fetch tool on the first round, otherwise it tends to fabricate
        // content from the URL slug instead of fetching. Skip this when URL
        // fetching is disabled (attachment flow): the attachment may contain URLs
        // but we must NOT fetch them.
        foreach ($messages as $m) {
            $content = $m['content'] ?? '';
            if (($m['role'] ?? '') === 'user' && is_string($content)
                && preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $content)) {
                return true;
            }
        }

        return false;
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
}
