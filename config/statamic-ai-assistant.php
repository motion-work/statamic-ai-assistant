<?php

use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;

return [

    /*
    |--------------------------------------------------------------------------
    | PROVIDER Key
    |--------------------------------------------------------------------------
    |
    | This plugin supports the following providers:
    | - auto (default): openrouter once OPENROUTER_API_KEY is set, infomaniak
    |   otherwise, so sites with only Infomaniak credentials keep working
    |   unchanged.
    | - openrouter: one API for every model; the addon picks the model per
    |   task (see "OpenRouter" below).
    | - infomaniak: Swiss-hosted. Use it when a client needs prompts and
    |   content to stay in Switzerland.
    |
    | Set the provider in your .env file using:
    | STATAMIC_AI_ASSISTANT_PROVIDER_NAME=auto
    | or openrouter / infomaniak.
    |
    | Data residency with OpenRouter: requests go to https://openrouter.ai and
    | the chosen model's provider, wherever it runs. In-region routing (EU) is
    | only available on OpenRouter's Business and Enterprise plans, by setting
    | OPENROUTER_BASE_URL=https://eu.openrouter.ai/api/v1; even then, billing
    | and account records are processed in the US. By default the addon only
    | routes to providers that do not collect prompts (data_collection: deny,
    | see openrouter_provider). For Swiss hosting, use infomaniak.
    |
    */

    'provider_name' => env('STATAMIC_AI_ASSISTANT_PROVIDER_NAME', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Translation functionality
    |--------------------------------------------------------------------------
    |
    | This allows you to disable the translations functionality.
    | disable it with your .env file using:
    | STATAMIC_AI_ASSISTANT_TRANSLATIONS=false
    |
    */

    'translations' => env('STATAMIC_AI_ASSISTANT_TRANSLATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | LLM fallback when DeepL is not configured
    |--------------------------------------------------------------------------
    |
    | Without DEEPL_API_KEY, translations (field, Bard, entry, bulk, terms,
    | navigation) run on the configured AI model instead of failing, but only
    | when INFOMANIAK_API_TOKEN is set. With neither key, translation UI is
    | hidden. The CP tells editors when the model translated instead of DeepL.
    | Glossary terms and style rules from the CP still apply (sent as
    | instructions in the prompt). DeepL is used again as soon as a key is set.
    |
    | Disable with your .env file (translations then fail without a key):
    | STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK=false
    |
    */

    'translation_llm_fallback' => (bool) env('STATAMIC_AI_ASSISTANT_TRANSLATION_LLM_FALLBACK', true),

    /*
    |--------------------------------------------------------------------------
    | Bulk (mass) translation tool
    |--------------------------------------------------------------------------
    |
    | Controls the "Bulk translations" CP page under Tools — the multi-step
    | wizard for translating entire collections or navigations at once.
    | Disabling this hides the Tools nav link and returns 404 for the page;
    | the per-entry Translate action and in-field translate buttons keep working.
    |
    | Disable with your .env file:
    | STATAMIC_AI_ASSISTANT_BULK_TRANSLATIONS=false
    |
    */

    'bulk_translations' => env('STATAMIC_AI_ASSISTANT_BULK_TRANSLATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Claude app connector
    |--------------------------------------------------------------------------
    |
    | The site exposes https://{APP_URL}/mcp for the Claude app (claude.ai,
    | Claude Desktop, and the mobile app). Any Control Panel account can sign
    | in. An email that is not a Control Panel account is refused, including
    | other addresses on the company domain. Claude translates the text itself.
    | This addon only reads entries and saves what Claude sends back.
    |
    | mcp_redirect_hosts: the apps allowed to connect, by the host of their
    | OAuth callback (comma-separated in the env). A sign-in only hands its
    | code to these hosts, so a stranger cannot register a client that sends
    | it to their own server. localhost is always allowed (local clients).
    | Add chatgpt.com to connect ChatGPT.
    |
    */

    'mcp_redirect_hosts' => array_values(array_filter(array_map(
        fn ($host) => strtolower(trim($host)),
        explode(',', (string) env('STATAMIC_AI_ASSISTANT_MCP_REDIRECT_HOSTS', 'claude.ai,claude.com')),
    ))),

    /*
    | mcp_cache_store: the cache store (from config/cache.php) that keeps
    | connected apps and their sign-ins. Empty uses the default store, which
    | `php artisan cache:clear` empties, signing everyone out of the
    | connector, often on every deploy. Use a store the deploy does not clear,
    | for example `database` (needs the cache table: `php artisan make:cache-table`).
    */

    'mcp_cache_store' => env('STATAMIC_AI_ASSISTANT_MCP_CACHE_STORE') ?: null,

    /*
    |--------------------------------------------------------------------------
    | INFOMANIAK product id
    |--------------------------------------------------------------------------
    |
    | Mandatory: If you use infomaniak, you need to provide your product ID to use the API!
    | Get your id: https://developer.infomaniak.com/docs/api/get/1/ai
    | Tips: Find your id in your infomaniak dashboard (computing->AI tools)
    |
    */
    'infomaniak_product_id' => env('INFOMANIAK_PRODUCT_ID'),

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Use the appropriate API key for your chosen provider.
    |
    | For OPENROUTER: set OPENROUTER_API_KEY in your environment.
    | For INFOMANIAK: set INFOMANIAK_API_TOKEN in your environment.
    |
    | Without the active provider's key, every AI feature is hidden in the
    | Control Panel (agent, field buttons, AI instructions, agent access).
    | DeepL translations, the glossary and the translate actions stay, and only
    | when DEEPL_API_KEY is set.
    |
    */

    'openrouter_api_key' => env('OPENROUTER_API_KEY'),
    'infomaniak_api_token' => env('INFOMANIAK_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Model / Product Identifiers
    |--------------------------------------------------------------------------
    |
    | For INFOMANIAK, use INFOMANIAK_PRODUCT_ID and INFOMANIAK_MODEL.
    |
    */
    // Infomaniak tiered models: the strong model does the heavy work (planning +
    // entry generation); the fast model handles lightweight tasks (target
    // selection, collection matching, block hints). Both are set in .env:
    //   INFOMANIAK_MODEL=<strong>        e.g. a larger, higher-quality model
    //   INFOMANIAK_MODEL_FAST=<fast>     e.g. a smaller, quicker model
    // If INFOMANIAK_MODEL_FAST is unset, the fast tier falls back to the strong
    // model — so behaviour is unchanged until you opt in.
    'infomaniak_model' => env('INFOMANIAK_MODEL', 'mistral24b'),
    'infomaniak_model_fast' => env('INFOMANIAK_MODEL_FAST') ?: env('INFOMANIAK_MODEL', 'mistral24b'),

    /*
    |--------------------------------------------------------------------------
    | OpenRouter
    |--------------------------------------------------------------------------
    |
    | OpenRouter (https://openrouter.ai) puts every model behind one API. The
    | addon decides which model answers, per task:
    |
    |   agent      AI agent planning and entry generation (tools, reasoning)
    |   light      small helpers: target selection, collection matching, hints
    |   field      Ai Text / Bard buttons: generate and refactor
    |   translate  LLM translation fallback (no DeepL key)
    |   vision     image understanding: alt text, analyze_image
    |
    | Each profile has:
    |   models      the model ids in fallback order (a list, or a comma-
    |               separated string in .env). OpenRouter moves to the next
    |               one when a model is down, rate limited or the prompt is
    |               too long. The first one is the model you get normally.
    |   preset      optional OpenRouter preset slug ("statamic-agent" or
    |               "@preset/statamic-agent"). Presets live in your OpenRouter
    |               account, so the model can be changed there for every site
    |               at once. With models set too, the request fields win over
    |               the preset; with no models, the preset alone decides.
    |   reasoning   reasoning effort: none, minimal, low, medium, high, xhigh,
    |               max, or null to leave the model's default. Quick tasks
    |               (every task but agent) ask for "none" while
    |               quick_tasks_without_thinking is on, so their models must
    |               accept reasoning effort "none" (some reasoning models
    |               don't: their models page says "mandatory").
    |   max_tokens  optional output budget when the caller sets none.
    |   temperature optional; null uses `temperature` above.
    |
    | The models below are the shipped defaults, picked in November 2026 from
    | https://openrouter.ai/models (tools + structured outputs for the agent,
    | image input for vision, cheap models that accept reasoning "none" for the
    | quick tasks). Revise them as models change.
    |
    */

    'openrouter_base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    // Shown in your OpenRouter activity next to the site URL (HTTP-Referer).
    // Empty uses APP_NAME.
    'openrouter_app_title' => env('OPENROUTER_APP_TITLE'),

    // Seconds to wait for one OpenRouter response (agent calls can be long).
    'openrouter_http_timeout' => max(30, (int) env('OPENROUTER_HTTP_TIMEOUT', 180)),

    'openrouter_profiles' => [
        'agent' => [
            // Strong tool-calling models with structured outputs. The agent
            // forces a tool on some turns (e.g. fetch a URL first): Claude
            // models with mandatory reasoning reject a forced tool_choice, so
            // they come last, where OpenRouter only falls back to them.
            'models' => env('OPENROUTER_AGENT_MODELS', 'openai/gpt-6.1-sol,google/gemini-4-argon,anthropic/claude-sonnet-5.5'),
            'preset' => env('OPENROUTER_AGENT_PRESET'),
            'reasoning' => env('OPENROUTER_AGENT_REASONING', 'medium'),
            'max_tokens' => null,
            'temperature' => null,
        ],
        'light' => [
            'models' => env('OPENROUTER_LIGHT_MODELS', 'anthropic/claude-haiku-5.5,openai/gpt-6-luna'),
            'preset' => env('OPENROUTER_LIGHT_PRESET'),
            'reasoning' => env('OPENROUTER_LIGHT_REASONING', 'none'),
            'max_tokens' => null,
            'temperature' => null,
        ],
        'field' => [
            'models' => env('OPENROUTER_FIELD_MODELS', 'anthropic/claude-haiku-5.5,openai/gpt-6-luna'),
            'preset' => env('OPENROUTER_FIELD_PRESET'),
            'reasoning' => env('OPENROUTER_FIELD_REASONING', 'none'),
            'max_tokens' => null,
            'temperature' => null,
        ],
        'translate' => [
            // Good German, French and Italian; Mistral as an EU-made fallback.
            'models' => env('OPENROUTER_TRANSLATE_MODELS', 'anthropic/claude-haiku-5.5,mistralai/mistral-large-4-0'),
            'preset' => env('OPENROUTER_TRANSLATE_PRESET'),
            'reasoning' => env('OPENROUTER_TRANSLATE_REASONING', 'none'),
            'max_tokens' => null,
            'temperature' => null,
        ],
        'vision' => [
            // Must accept image input. Empty models and preset disable image analysis.
            'models' => env('OPENROUTER_VISION_MODELS', 'anthropic/claude-haiku-5.5,openai/gpt-6-luna'),
            'preset' => env('OPENROUTER_VISION_PRESET'),
            'reasoning' => env('OPENROUTER_VISION_REASONING', 'none'),
            'max_tokens' => null,
            'temperature' => null,
        ],
        'image' => [
            // Image generation (the agent's generate_image tool, see
            // image_generation below). OpenRouter's images endpoint takes one
            // model per request, so the addon tries these in order itself.
            // Shipped defaults (Nov 2026): FLUX 3 Klein, about $0.009 per 1K
            // image; Gemini 3.1 Flash Image as the fallback, about $0.07 per
            // image. Only `models` applies; presets and reasoning do not.
            'models' => env('OPENROUTER_IMAGE_MODELS', 'black-forest-labs/flux-3-image-klein,google/gemini-3.1-flash-image'),
        ],
        'embedding' => [
            // Semantic search (see semantic_search below). One model: vectors
            // from different models cannot be compared, so changing the model
            // or the dimensions invalidates the index until it is rebuilt.
            // openai/text-embedding-3-small: about $0.02 per million tokens,
            // multilingual, and it can shorten its vectors to `dimensions`.
            'models' => env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
            'dimensions' => max(64, (int) env('OPENROUTER_EMBEDDING_DIMENSIONS', 512)),
        ],
    ],

    /*
    | Which upstream providers OpenRouter may route to (the `provider` object,
    | https://openrouter.ai/docs/features/provider-routing):
    |
    |   data_collection  "deny" (default) uses only providers that do not store
    |                    or train on prompts; "allow" uses any provider.
    |   zdr              true uses only zero-data-retention endpoints (fewer
    |                    models qualify).
    |   only / ignore    provider slugs to allow or skip (comma-separated).
    |   sort             "price", "throughput" or "latency"; empty keeps
    |                    OpenRouter's balanced default.
    |
    | Structured output (response_format) also sends require_parameters, so the
    | schema never lands on a provider that ignores it.
    |
    | Data residency: OpenRouter keeps prompts and completions in the EU only
    | on its Business and Enterprise plans, through
    | OPENROUTER_BASE_URL=https://eu.openrouter.ai/api/v1. Account and billing
    | records are processed in the US either way. For Swiss hosting use the
    | infomaniak provider.
    */
    'openrouter_provider' => [
        'data_collection' => env('OPENROUTER_DATA_COLLECTION', 'deny'),
        'zdr' => (bool) env('OPENROUTER_ZDR', false),
        'only' => env('OPENROUTER_PROVIDER_ONLY', ''),
        'ignore' => env('OPENROUTER_PROVIDER_IGNORE', ''),
        'sort' => env('OPENROUTER_PROVIDER_SORT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenRouter structured outputs and prompt caching (AI agent)
    |--------------------------------------------------------------------------
    |
    | Structured outputs: the AI agent sends each entry's JSON Schema (built
    | from its blueprint) as `response_format`, so the model answers with JSON
    | in exactly that shape and the repair round is rarely needed. Requests then
    | only go to providers that support it (require_parameters), and carry no
    | temperature (the OpenAI and Anthropic reasoning endpoints do not accept
    | one). If a provider rejects the schema, the entry is generated again
    | without it, so this never fails a generation. Very deeply nested
    | blueprints get no schema. OpenRouter only.
    |
    | Prompt caching: the agent's system prompt (instructions + field schema)
    | is large and repeats on every round and every entry of a session. The
    | agent session id is sent as `session_id`, so OpenRouter keeps a session
    | on one provider where that prefix stays cached, and agent calls carry a
    | `cache_control` breakpoint, which Anthropic models need before they cache
    | (OpenAI, Google and others cache automatically). Cached input costs
    | roughly 0.1x (Anthropic) to 0.5x (OpenAI) of the normal input price;
    | Anthropic charges 1.25x for the first write.
    |
    */
    'openrouter_structured_outputs' => (bool) env('OPENROUTER_STRUCTURED_OUTPUTS', true),
    'openrouter_prompt_caching' => (bool) env('OPENROUTER_PROMPT_CACHING', true),

    /*
    |--------------------------------------------------------------------------
    | AI agent — web search
    |--------------------------------------------------------------------------
    |
    | Editors switch web search on per message with the "Web search" button in
    | the agent's composer (off by default; it stays on for the follow-ups of
    | that chat). The agent can then look things up on the web, cite its
    | sources under the reply, and open the cited pages. Images are still only
    | saved from pages the editor gave: pages reached through a search never
    | make their images saveable.
    |
    | The search runs inside OpenRouter (the `openrouter:web_search` server
    | tool), so it needs the OpenRouter provider. It is not offered when
    | OPENROUTER_BASE_URL points at a regional host (eu./us.openrouter.ai): the
    | EU host has no search engine. Set enabled to false to remove the button
    | for everyone.
    |
    | Privacy: search queries (written by the model from the editor's request)
    | go to the search provider (Exa, Parallel, Perplexity or the model's own
    | search). OpenRouter's zero-data-retention setting does not cover them.
    |
    | Cost: about $0.007–0.015 per search on top of the tokens, depending on
    | the engine. max_results caps the results per search, max_total_results
    | the results per request (across all searches).
    |
    | allowed_domains / excluded_domains: comma-separated domains to restrict
    | or exclude, e.g. "admin.ch,bfs.admin.ch".
    |
    */
    'web_search' => [
        'enabled' => (bool) env('STATAMIC_AI_ASSISTANT_WEB_SEARCH', true),
        'engine' => env('STATAMIC_AI_ASSISTANT_WEB_SEARCH_ENGINE', 'auto'),
        'max_results' => max(1, min(25, (int) env('STATAMIC_AI_ASSISTANT_WEB_SEARCH_MAX_RESULTS', 5))),
        'max_total_results' => max(1, (int) env('STATAMIC_AI_ASSISTANT_WEB_SEARCH_MAX_TOTAL_RESULTS', 15)),
        'allowed_domains' => env('STATAMIC_AI_ASSISTANT_WEB_SEARCH_ALLOWED_DOMAINS', ''),
        'excluded_domains' => env('STATAMIC_AI_ASSISTANT_WEB_SEARCH_EXCLUDED_DOMAINS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic search (AI agent and Claude connector)
    |--------------------------------------------------------------------------
    |
    | Finds entries by meaning, not only by matching words: "update the page
    | about care" finds "Garantie und Reparaturen". Used by the AI agent's
    | find_entries, search_entry_content and read tools, and by the Claude
    | connector's find_entries. Results are merged with the keyword search, so
    | an exact title or slug match still comes first.
    |
    | Every search falls back to the keyword search alone, unchanged, while
    | semantic search cannot run: another provider than OpenRouter or no
    | OPENROUTER_API_KEY, `enabled` false, no index built yet for the site,
    | more entries than `max_entries`, or a failed embedding request.
    |
    | Index: one vector per entry localization (title, slug and text), stored
    | per site under `path` (default storage/app/statamic-ai-assistant/embeddings).
    | Build it once with `php artisan statamic-ai-assistant:embeddings build`
    | (or "Rebuild index" on the AI usage page); after that, saving or deleting
    | an entry updates its vector on `queue`. The model and dimensions come
    | from the `embedding` profile above.
    |
    | Privacy: entry text (and each search query) is sent to the embedding
    | provider through OpenRouter, with the openrouter_provider settings.
    | Cost: about $0.02 per million tokens, roughly $0.03 to index 1,000
    | entries; a search costs a fraction of a cent.
    |
    |   max_entries     above this many entries per site the vectors are not
    |                   loaded and the keyword search runs alone (memory).
    |   min_similarity  cosine similarity (0–1) below which a match is dropped.
    |
    */
    'semantic_search' => [
        'enabled' => (bool) env('STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH', true),
        'path' => env('STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH_PATH'),
        'queue' => env('STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH_QUEUE'),
        'max_entries' => max(1, (int) env('STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH_MAX_ENTRIES', 5000)),
        'min_similarity' => (float) env('STATAMIC_AI_ASSISTANT_SEMANTIC_SEARCH_MIN_SIMILARITY', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image generation (AI agent)
    |--------------------------------------------------------------------------
    |
    | The agent's generate_image tool, on the OpenRouter provider only, with
    | the `image` profile's models (openrouter_profiles). The agent generates
    | an image only when an editor explicitly asks for one; it never fills
    | empty image fields with generated images on its own.
    |
    | On/off: super admins switch it in the Control Panel (AI assistant → Who
    | has access → Image generation); it is off until they do. Setting
    | STATAMIC_AI_ASSISTANT_IMAGE_GENERATION to true or false overrides that
    | switch for the site (the CP then shows it locked); leave it unset to let
    | the CP decide.
    |
    | Generated images are saved as assets in `folder` of the entry's image
    | container, with `ai_generated: true` and the prompt (`ai_prompt`) in
    | their metadata, and an alt text from the vision model. They are saved
    | when generated, before the entry is: images of a discarded preview stay
    | in that folder, so it is the place to clean up.
    |
    |   max_per_entry   generate_image calls per generated entry.
    |   aspect_ratio    default when the agent names none ("16:9", "1:1", …).
    |   resolution      "512", "1K", "2K", … (bigger costs more on most models).
    |   output_format   "jpeg" or "png"; FLUX rejects "webp".
    |
    | Cost: about $0.009 (FLUX 3 Klein) to $0.07 (Gemini) per image, shown on
    | the AI usage page.
    |
    */
    'image_generation' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION'),
        'folder' => env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_FOLDER', 'ai-generated'),
        'max_per_entry' => max(1, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_MAX_PER_ENTRY', 4)),
        'aspect_ratio' => env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_ASPECT_RATIO', '16:9'),
        'resolution' => env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_RESOLUTION', '1K'),
        'output_format' => env('STATAMIC_AI_ASSISTANT_IMAGE_GENERATION_FORMAT', 'jpeg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reasoning ("thinking") for quick tasks
    |--------------------------------------------------------------------------
    |
    | Reasoning models (Kimi K2.6, Qwen3.5/3.8, …) think before answering —
    | 1–2k tokens even for a single sentence. For quick tasks (Ai Text / Bard
    | generate and refactor, alt text, image analysis, fast-tier helpers) that
    | means ≈15s instead of ≈1s, and an empty reply when the max_tokens budget
    | runs out first. When true, those calls ask the model to answer directly.
    | The AI agent's planning and entry generation always keep thinking on.
    |
    | Infomaniak sends it as chat_template_kwargs (skipped for Mistral models,
    | which reject it and don't think anyway); OpenRouter as reasoning effort
    | "none". Set false to keep thinking everywhere (OpenRouter quick tasks then
    | use their profile's reasoning setting).
    |
    */
    'quick_tasks_without_thinking' => (bool) env('STATAMIC_AI_ASSISTANT_QUICK_TASKS_WITHOUT_THINKING', true),

    /*
    |--------------------------------------------------------------------------
    | Infomaniak HTTP client
    |--------------------------------------------------------------------------
    |
    | Laravel's HTTP client defaults to a 30 second timeout. The entry generator
    | sends large system prompts (full blueprint schema) and may use up to
    | generator_max_tokens — Infomaniak often needs longer than 30s to respond.
    | Multi-entry runs and tool loops can take several minutes per provider call;
    | raise this if you see cURL timeout 28 / operation timed out in logs.
    |
    | Default is 180s: generous for a single generation call, but low enough that
    | one stuck/slow call fails in ~3 min instead of hanging for 10. Sites running
    | very large models with big outputs can raise it via the env var.
    |
    */

    'infomaniak_http_timeout' => max(30, (int) env('STATAMIC_AI_ASSISTANT_INFOMANIAK_HTTP_TIMEOUT', 180)),

    /*
    | Max attempts for an AI provider call when it fails transiently (HTTP
    | 429/500/502/503/504 or a dropped connection). Hard timeouts are NOT retried
    | — the model is just slow, and retrying only stacks waits. 1 disables retry.
    */
    'ai_http_retry_times' => max(1, (int) env('STATAMIC_AI_ASSISTANT_AI_HTTP_RETRY_TIMES', 3)),

    /*
    |--------------------------------------------------------------------------
    | Prompt Preface
    |--------------------------------------------------------------------------
    |
    | You can specify a statement that is sent as context to the API before generating a response.
    | Set STATAMIC_AI_ASSISTANT_PREFACE in your .env file to override the text for the initial prompts
    | Set STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE in your .env file to override the text for the text refactoring
    |
    */

    'prompt_preface' => env(
        'STATAMIC_AI_ASSISTANT_PREFACE',
        'You are a seasoned SEO expert who writes clear, professional, seo friendly and engaging articles in plain text.
         Your task is to produce fully readable articles for an end user.
         Do not include any markdown formatting, HTML tags, header tags, meta descriptions, or any other markup.
         Write only the final article text in plain text, ensuring it is clear, direct, and free of extraneous formatting elements.'
    ),

    'prompt_refactor_preface' => env(
        'STATAMIC_AI_ASSISTANT_REFACTOR_PREFACE',
        'You are a professional text refactoring expert. Your only task is to transform the provided text according to the specific instructions given by the user. 
        Output solely the final refactored text in plain text, DO NOT INCLUDE the user instructions, any commentary, or any additional text. 
        Preserve the original language and maintain a similar length to the input text unless instructed otherwise.'
    ),

    'prompt_html_refactor_preface' => env(
        'STATAMIC_AI_ASSISTANT_HTML_REFACTOR_PREFACE',
        'You are a professional text refactoring expert. 
        Your only task is to transform the provided HTML text according to the specific instructions given by the user. 
        Output solely the final refactored HTML! DO NOT INCLUDE the user instructions, any commentary, or any additional text. 
        ALWAYS RENDER the output as valid HTML. Keep all links intact unless the user specifically instructs you to modify them, 
        and always strive to preserve the original HTML structure unless instructed otherwise.
        ALWAYS update lorem ipsum. Preserve the original language and maintain a similar length to the input text unless otherwise specified.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Generation Parameters
    |--------------------------------------------------------------------------
    |
    | Define generation parameters
    |
    |
    */

    'temperature' => env('STATAMIC_AI_ASSISTANT_TEMPERATURE', 0.5),
    'max_tokens' => env('STATAMIC_AI_ASSISTANT_MAX_TOKENS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Translation Settings
    |--------------------------------------------------------------------------
    |
    | Configure how the bulk translation system works.
    |
    | translation_mode: 'sync' (always synchronous), 'async' (always use queue),
    |                   'auto' (sync for small batches, async for larger)
    | translation_sync_threshold: when mode is 'auto', batches above this count use async
    | translation_queue: the queue name for async translation jobs
    |
    */

    'translation_mode' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_MODE', 'auto'),
    'translation_sync_threshold' => 3,
    'translation_queue' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_QUEUE', 'translations'),

    /*
    |--------------------------------------------------------------------------
    | Linked entries (DeepL translation graph depth)
    |--------------------------------------------------------------------------
    |
    | When translating an entry, linked entries (entries/link fields, Bard entry
    | links, nested replicator data) can be translated recursively so IDs point
    | to localized siblings. This value is the maximum depth of that recursion
    | (0 = only remap IDs if a translation already exists; 1 = one hop, default).
    | Admins override via .env — the Control Panel does not expose this control.
    |
    */

    'linked_entries_max_depth' => max(0, min(5, (int) env('STATAMIC_AI_ASSISTANT_LINKED_ENTRIES_MAX_DEPTH', 1))),

    /*
    |--------------------------------------------------------------------------
    | AI instructions (site instructions, set and field hints)
    |--------------------------------------------------------------------------
    |
    | YAML file path for per-block AI hints (replicator / components sets).
    | Default is under content/ so it can be versioned in git. Set
    | STATAMIC_AI_ASSISTANT_SET_HINTS_PATH to an absolute path, or a path
    | relative to the project root, to override.
    |
    | Legacy location (pre–configurable path): storage/app/statamic-ai-assistant/set-hints.yaml
    | — automatically migrated once when the new file does not exist yet.
    |
    */

    'set_hints_path' => env('STATAMIC_AI_ASSISTANT_SET_HINTS_PATH', base_path('content/statamic-ai-assistant/set-hints.yaml')),

    /*
    |--------------------------------------------------------------------------
    | DeepL glossary & style rules storage
    |--------------------------------------------------------------------------
    |
    | YAML file paths for the CP-managed DeepL glossary (term table synced to a
    | DeepL v3 multilingual glossary) and the per-language style rules (synced
    | to DeepL v3 style rules). Defaults live under content/ so they can be
    | versioned in git.
    |
    */

    'translation_glossary_path' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_GLOSSARY_PATH', base_path('content/statamic-ai-assistant/translation-glossary.yaml')),

    'translation_style_rules_path' => env('STATAMIC_AI_ASSISTANT_TRANSLATION_STYLE_RULES_PATH', base_path('content/statamic-ai-assistant/translation-style-rules.yaml')),

    /*
    |--------------------------------------------------------------------------
    | AI agent access configuration storage
    |--------------------------------------------------------------------------
    |
    | YAML file storing which roles / users may use each gated capability
    | (AI agent, bulk translations, agent settings) plus the per-role /
    | per-user entry-generation limits. Managed by super admins from the
    | "Who has access" page in the AI assistant nav section. Default under content/
    | so it can be versioned in git.
    |
    */

    'access_path' => env('STATAMIC_AI_ASSISTANT_ACCESS_PATH', base_path('content/statamic-ai-assistant/access.yaml')),

    /*
    |--------------------------------------------------------------------------
    | Glossary terminology takes precedence over style tone
    |--------------------------------------------------------------------------
    |
    | DeepL applies glossaries reliably ("hard") only with the classic model
    | (model_type=latency_optimized). Any request carrying a style_id is served
    | by the next-gen model, which treats glossary entries as SOFT hints — so a
    | term like "Apartment" → "apart." is often ignored when a style rule is
    | also active.
    |
    | When this is true (default) and BOTH a glossary term and a style rule
    | would apply to the same translation, the text is split: segments that
    | contain a glossary term are translated on the classic model so the term is
    | enforced, and the remaining segments keep the style rule. Set to false to
    | always prefer the style rule (glossary stays soft) in one request.
    |
    */

    'prefer_glossary_over_style' => env('STATAMIC_AI_ASSISTANT_PREFER_GLOSSARY_OVER_STYLE', true),

    /*
    |--------------------------------------------------------------------------
    | Figma OAuth (entry generator design context)
    |--------------------------------------------------------------------------
    |
    | Create an app at https://www.figma.com/developers/apps and register the
    | redirect URL shown on the AI instructions page. Store credentials only in .env
    | (never commit). Run `php artisan config:clear` after changing .env.
    |
    | Legacy: credentials were stored in storage/app/statamic-ai-assistant/figma-app.yaml
    | — delete that file after migrating to these variables.
    |
    */

    'figma_oauth_client_id' => env('STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_ID', ''),
    'figma_oauth_client_secret' => env('STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | AI Entry Generator
    |--------------------------------------------------------------------------
    |
    | Master switch for the AI entry generator feature (the floating "AI agent"
    | assistant button + generator). When false, the feature is off for everyone,
    | including super admins.
    |
    | generator_max_tokens: Higher than the default max_tokens because the
    | generator produces content for all fields in a single LLM call.
    |
    */

    'entry_generator' => (bool) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR', true),

    /*
    | Show the "AI instructions" entry in the CP nav (AI assistant section)
    |--------------------------------------------------------------------------
    |
    | Independent from `entry_generator`: set this to false to keep the agent
    | itself running while hiding the per-set hints settings page from the
    | sidebar (e.g. for editor accounts that should not see addon settings).
    |
    */

    'agent_settings_nav' => env('STATAMIC_AI_ASSISTANT_AGENT_SETTINGS_NAV', true),

    /*
    | AI agent — queued batch generation (after planner)
    |--------------------------------------------------------------------------
    |
    | Multi-entry content generation runs in Bus::chain jobs so the CP request
    | ends after the plan. Requires QUEUE_CONNECTION other than "sync".
    | preferred_paths are updated between chained jobs.
    |
    */
    'entry_generator_batch' => [
        'queue' => env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_QUEUE', 'default'),
        'job_timeout' => max(60, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_JOB_TIMEOUT', 300)),
        'job_tries' => max(1, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_JOB_TRIES', 2)),
    ],

    'generator_max_tokens' => env('STATAMIC_AI_ASSISTANT_GENERATOR_MAX_TOKENS', 4000),

    /*
    | Max completion tokens for the multi-entry planner only. Listing → many plan
    | rows needs a large JSON; if this is too low the model truncates, parsing
    | fails, and the flow falls back to a single entry (one card) while the
    | generator then hammers fetch_page_content for every URL.
    */
    'entry_generator_planner_max_output_tokens' => max(4096, min(131072, (int) env('STATAMIC_AI_ASSISTANT_PLANNER_MAX_OUTPUT_TOKENS', 12000))),

    /*
    |--------------------------------------------------------------------------
    | Entry generator — URL fetch tool (LLM function calling)
    |--------------------------------------------------------------------------
    |
    | When enabled and the AI provider supports tools, the model may call
    | fetch_page_content to load full article/detail pages (e.g. from listing
    | teasers). Requires prompt_url_fetch.enabled and a tool-capable model.
    |
    */
    'entry_generator_fetch_url_tool' => (bool) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_FETCH_URL_TOOL', true),

    // When true (default), the agent may only fetch URLs the user actually
    // provided in their request (plus same-site links within those pages) — it
    // will never open URLs it invents. This stops it from spidering the open web
    // and, in particular, from wasting the fetch budget / time on guessed URLs
    // that don't exist. Set to false to let the model fetch any public URL.
    'entry_generator_restrict_fetch_to_prompt_urls' => (bool) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_RESTRICT_FETCH_TO_PROMPT_URLS', true),

    // Max agentic tool-loop rounds per request. Rounds are consumed on demand
    // (a two-entry create finishes in a handful), so this only bounds long bulk
    // jobs — e.g. "add alt text to every image in a folder", where each item
    // costs ~2 rounds (analyze_image + update_asset). Default lowered to 40: a
    // safety net against a misbehaving model grinding through slow rounds. Bulk
    // jobs still finish ~20 items/run; sites doing larger sweeps can raise it via
    // the env var (clamped to 60).
    'entry_generator_tool_max_rounds' => max(1, min(60, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_ROUNDS', 40))),
    'entry_generator_tool_max_fetches' => max(1, min(40, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_FETCHES', 100))),
    // Max read_entry_structure calls per request (the agent reads existing entries
    // to mirror their layout/components when creating or updating an entry).
    'entry_generator_tool_max_read_entries' => max(1, min(40, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_READ_ENTRIES', 20))),

    // Max calls PER read tool for the generic CMS-context tools (read_globals,
    // read_nav_tree, list_taxonomies) that let the agent inspect globals, the
    // navigation hierarchy, and taxonomy terms while planning/writing.
    'entry_generator_tool_max_cms_reads' => max(1, min(40, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_CMS_READS', 12))),

    /*
    |--------------------------------------------------------------------------
    | AI agent — advanced structure tools
    |--------------------------------------------------------------------------
    |
    | Structural tools (create/configure collections, blueprints, taxonomies)
    | for the agent. Per-user access is managed in the CP under AI agent
    | access ('advanced_tools' feature, default-deny, supers always pass);
    | this switch lets a site disable the whole pack regardless of grants.
    | Structural writes apply immediately (no draft/review step), so each
    | WRITE tool also gets a small per-request call budget.
    |
    */
    'advanced_tools' => (bool) env('STATAMIC_AI_ASSISTANT_ADVANCED_TOOLS', true),
    'entry_generator_tool_max_structural_writes' => max(1, min(20, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_STRUCTURAL_WRITES', 6))),

    // Max update_asset calls per request (asset metadata writes: alt texts, captions).
    'entry_generator_tool_max_asset_writes' => max(1, min(100, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_ASSET_WRITES', 40))),

    // Max analyze_image (vision) calls per request. This is the throughput ceiling
    // for content-based alt text over a folder of images, so it tracks the asset
    // write budget rather than the smaller generic CMS-read cap.
    'entry_generator_tool_max_image_analyses' => max(1, min(100, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_IMAGE_ANALYSES', 40))),

    /*
    |--------------------------------------------------------------------------
    | Vision model (image understanding)
    |--------------------------------------------------------------------------
    |
    | Optional multimodal model for the analyze_image tool (e.g. generating
    | alt texts from what an image actually shows). Set to a vision-capable
    | model id from your Infomaniak AI product (GET /1/ai/models lists them);
    | leave empty to disable image analysis entirely. Infomaniak only:
    | OpenRouter uses the `vision` profile in openrouter_profiles.
    |
    */
    'infomaniak_vision_model' => (string) env('STATAMIC_AI_ASSISTANT_INFOMANIAK_VISION_MODEL', ''),

    /*
    |--------------------------------------------------------------------------
    | AI agent — multi-entry plan cap
    |--------------------------------------------------------------------------
    |
    | Maximum entries the planner may return in one generate request. Increase
    | STATAMIC_AI_ASSISTANT_AGENT_MAX_PLAN_ENTRIES if users need larger
    | batches (capped at 500 to avoid runaway API use).
    |
    */
    'agent_max_plan_entries' => max(1, min(500, (int) env('STATAMIC_AI_ASSISTANT_AGENT_MAX_PLAN_ENTRIES', 100))),

    /*
    |--------------------------------------------------------------------------
    | Editor entry-creation limit (non-super users)
    |--------------------------------------------------------------------------
    |
    | When enabled (default), only super users can create/update multiple
    | entries in a single AI agent request. Everyone else is capped to a
    | SINGLE entry per request — editors can still use the agent, but a prompt
    | like "create every page of this website" can never fan out into a bulk
    | run for a non-admin. Super users keep the full agent_max_plan_entries
    | cap. Disable to let all users run multi-entry batches:
    |
    | EDITOR_LIMIT_ENTRIES=false
    |
    */
    'editor_limit_entries' => (bool) env('EDITOR_LIMIT_ENTRIES', true),

    'prompt_generator_preface' => env(
        'STATAMIC_AI_ASSISTANT_GENERATOR_PREFACE',
        'You are a CMS content creation assistant. Your task is to generate structured content for a website entry. You must respond with ONLY a valid JSON object. No markdown code fences, no commentary, no explanation — just the raw JSON.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Entry generator fallback link
    |--------------------------------------------------------------------------
    |
    | When the AI leaves a Statamic "link" field empty, the generator can set
    | it to a stable internal entry (e.g. home) so the CP always loads valid data.
    |
    */
    'generator_fallback_link' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_GENERATOR_FALLBACK_LINK', true),
        'collection' => env('STATAMIC_AI_ASSISTANT_FALLBACK_LINK_COLLECTION', 'pages'),
        'slug' => env('STATAMIC_AI_ASSISTANT_FALLBACK_LINK_SLUG', 'home'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt URL fetching (Jina Reader)
    |--------------------------------------------------------------------------
    |
    | When the user prompt contains http(s) URLs, the server fetches readable
    | text via https://r.jina.ai/{your-url} and appends it to the LLM context.
    | Failures (blocked sites, timeouts) become user-visible warnings only.
    |
    | Set STATAMIC_AI_ASSISTANT_JINA_API_KEY or JINA_API_KEY (see .env.example).
    | The key is sent as Authorization: Bearer {key} on every Jina Reader request.
    |
    */

    'prompt_url_fetch' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_PROMPT_URL_FETCH', true),
        'reader_base' => rtrim(env('STATAMIC_AI_ASSISTANT_JINA_READER_BASE', 'https://r.jina.ai'), '/'),
        // How the reader returns content. 'html' (default) fetches raw HTML and
        // runs our own extraction (HtmlReadableExtractor) — robust against Jina's
        // built-in markdown mode, which drops the real article on listing-heavy
        // pages. Set to 'markdown' to use Jina's own extraction as a kill-switch.
        'reader_format' => env('STATAMIC_AI_ASSISTANT_JINA_READER_FORMAT', 'html'),
        'timeout' => max(5, (int) env('STATAMIC_AI_ASSISTANT_JINA_TIMEOUT', 25)),
        'max_urls' => max(1, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_URLS', 5)),
        'max_chars_per_url' => max(1000, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_CHARS', 12000)),
        'max_total_chars' => max(5000, (int) env('STATAMIC_AI_ASSISTANT_JINA_MAX_TOTAL_CHARS', 40000)),
        'api_key' => env('STATAMIC_AI_ASSISTANT_JINA_API_KEY') ?: env('JINA_API_KEY'),

        // CSS selectors stripped from the page by Jina Reader before extraction
        // (passed via the X-Remove-Selector header). Keeps site nav, header,
        // footer, sidebars and consent-manager dialogs (Cookiebot, OneTrust,
        // Usercentrics) out of the fetched text — consent banners otherwise
        // inject huge cookie-declaration blocks that exhaust the char budget and
        // truncate the real page content. Applies to every Jina fetch path
        // (inline prompt URLs and the entry-generator tool). Override
        // per-project if your source site uses different class names
        // (e.g. ".global-nav, #site-footer"). Set to an empty string to disable.
        // The default lives on PromptUrlFetcher so code and config can't drift.
        'remove_selector' => env(
            'STATAMIC_AI_ASSISTANT_REMOVE_SELECTOR',
            PromptUrlFetcher::DEFAULT_REMOVE_SELECTOR,
        ),

        // Fetch order for reading a page. By default the Jina reader is tried
        // first (it is harder to block and can render JS), and a plain direct
        // HTTP fetch is the fallback for when Jina returns junk (e.g. it handed
        // back an empty <body> for some TYPO3 sites). Set to true to try the
        // direct fetch first instead (faster, no third-party dependency), with
        // Jina as the fallback. Either way, whichever source yields usable
        // content first wins, and Jina's markdown mode is the final fallback.
        'direct_first' => (bool) env('STATAMIC_AI_ASSISTANT_URL_FETCH_DIRECT_FIRST', false),

        // User-Agent used for the direct fetch. A browser-like UA avoids naive
        // bot blocking on many sites. Override if a source site needs a specific one.
        'user_agent' => env(
            'STATAMIC_AI_ASSISTANT_URL_FETCH_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote image copying (save_remote_image tool)
    |--------------------------------------------------------------------------
    |
    | While the entry generator writes content from a source URL, the LLM can
    | call the save_remote_image tool to download images from that page and
    | attach them to the entry's image fields (so a copied page keeps its own
    | imagery instead of getting random container assets).
    |
    | Images are stored in the container the entry's own asset fields point at
    | (auto-detected from the blueprint), which is the only container they can be
    | assigned from — so they reliably land on the page being created.
    |
    */

    'image_fetch' => [
        'enabled' => env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH', true),

        // Folder inside the container where copied images are stored.
        'folder' => env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_FOLDER', 'ai-agent-fetched'),

        // Asset container that downloaded images go into when the blueprint
        // does not dictate one. If null, the first available container is used.
        // Set to the handle (e.g. "images") of the container your blueprints'
        // assets fields point at.
        'asset_container' => env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_ASSET_CONTAINER', null),

        // Generate an alt text (one quick LLM call, vision when available) for
        // every downloaded image BEFORE it is saved, so assets never enter the
        // site without alt. Disable to skip the extra call per image.
        'generate_alt' => (bool) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_GENERATE_ALT', true),

        // Per-entry caps so a runaway generation can't flood the disk.
        'max_images' => max(0, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_MAX', 30)),
        'max_bytes' => max(1024, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_MAX_BYTES', 10 * 1024 * 1024)),
        'timeout' => max(1, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_TIMEOUT', 20)),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI usage log
    |--------------------------------------------------------------------------
    |
    | Every AI call (agent, field buttons, LLM translations, image analysis) is
    | logged with its task, model, tokens and duration, plus its cost in USD
    | when the provider reports one (OpenRouter does; Infomaniak does
    | not). Super admins see it on the "AI usage" CP page, next to the
    | OpenRouter key's spending limit.
    |
    | The log is flat files, one JSON line per call in `<YYYY-MM>.jsonl` under
    | `usage_path` (default: storage/app/statamic-ai-assistant/usage). Recording
    | never interrupts an AI call: a write error is logged and skipped. Months
    | older than `usage_retention_months` (the current month counts as one)
    | are deleted, checked at most once a day.
    |
    | It records who started a call (the CP user id) but no prompts or replies.
    |
    */
    'usage_tracking' => (bool) env('STATAMIC_AI_ASSISTANT_USAGE_TRACKING', true),
    'usage_retention_months' => max(1, (int) env('STATAMIC_AI_ASSISTANT_USAGE_RETENTION_MONTHS', 12)),
    'usage_path' => env('STATAMIC_AI_ASSISTANT_USAGE_PATH'),

];
