<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PROVIDER Key
    |--------------------------------------------------------------------------
    |
    | This plugin supports the following providers for now:
    | - infomaniak (CH, respect the data of your clients)
    | - groq (US, speed)
    |
    | Set the provider in your .env file using:
    | PROVIDER_NAME=infomaniak
    | or
    | PROVIDER_NAME=groq
    |
    */

    'provider_name' => env('STATAMIC_AI_ASSISTANT_PROVIDER_NAME', 'infomaniak'),

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
    | For GROQ: set GROQ_API_KEY in your environment.
    | For INFOMANIAK: set INFOMANIAK_API_TOKEN in your environment.
    |
    */

    'groq_api_key' => env('GROQ_API_KEY'),
    'infomaniak_api_token' => env('INFOMANIAK_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Model / Product Identifiers
    |--------------------------------------------------------------------------
    |
    | For GROQ, use GROQ_MODEL.
    | For INFOMANIAK, use INFOMANIAK_PRODUCT_ID and INFOMANIAK_MODEL.
    |
    */
    'groq_model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),

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
    | Infomaniak HTTP client
    |--------------------------------------------------------------------------
    |
    | Laravel's HTTP client defaults to a 30 second timeout. The entry generator
    | sends large system prompts (full blueprint schema) and may use up to
    | generator_max_tokens — Infomaniak often needs longer than 30s to respond.
    | Multi-entry runs and tool loops can take several minutes per provider call;
    | raise this if you see cURL timeout 28 / operation timed out in logs.
    |
    */

    'infomaniak_http_timeout' => max(30, (int) env('STATAMIC_AI_ASSISTANT_INFOMANIAK_HTTP_TIMEOUT', 600)),

    /*
    | Groq HTTP client (Guzzle) timeout for direct chat/completions calls.
    */
    'groq_http_timeout' => max(30, (int) env('STATAMIC_AI_ASSISTANT_GROQ_HTTP_TIMEOUT', 600)),

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
    | BOLD agent settings (set hints)
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
    | redirect URL shown in BOLD agent settings. Store credentials only in .env
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
    | Master switch for the AI entry generator feature (the floating "BOLD agent"
    | assistant button + generator). When false, the feature is off for everyone,
    | including super admins.
    |
    | generator_max_tokens: Higher than the default max_tokens because the
    | generator produces content for all fields in a single LLM call.
    |
    */

    'entry_generator' => (bool) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR', true),

    /*
    | Show the BOLD agent to non-super editors
    |--------------------------------------------------------------------------
    |
    | ENABLE_AGENT_FOR_EDITORS=false hides the agent (floating button + settings)
    | from non-super users only. Super admins ALWAYS have access, regardless of
    | this flag or the environment. Requires `entry_generator` to be on.
    |
    */

    'enable_agent_for_editors' => (bool) env('ENABLE_AGENT_FOR_EDITORS', true),

    /*
    | Show the "BOLD agent settings" entry in the CP Settings sidebar
    |--------------------------------------------------------------------------
    |
    | Independent from `entry_generator`: set this to false to keep the agent
    | itself running while hiding the per-set hints settings page from the
    | sidebar (e.g. for editor accounts that should not see addon settings).
    |
    */

    'bold_agent_settings_nav' => env('STATAMIC_AI_ASSISTANT_BOLD_AGENT_SETTINGS_NAV', true),

    /*
    | BOLD agent — queued batch generation (after planner)
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
    'entry_generator_tool_max_rounds' => max(1, min(24, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_ROUNDS', 120))),
    'entry_generator_tool_max_fetches' => max(1, min(40, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_FETCHES', 100))),
    // Max read_entry_structure calls per request (the agent reads existing entries
    // to mirror their layout/components when creating or updating an entry).
    'entry_generator_tool_max_read_entries' => max(1, min(40, (int) env('STATAMIC_AI_ASSISTANT_ENTRY_GENERATOR_TOOL_MAX_READ_ENTRIES', 20))),

    /*
    |--------------------------------------------------------------------------
    | BOLD agent — multi-entry plan cap
    |--------------------------------------------------------------------------
    |
    | Maximum entries the planner may return in one generate request. Increase
    | STATAMIC_AI_ASSISTANT_BOLD_AGENT_MAX_PLAN_ENTRIES if users need larger
    | batches (capped at 500 to avoid runaway API use).
    |
    */
    'bold_agent_max_plan_entries' => max(1, min(500, (int) env('STATAMIC_AI_ASSISTANT_BOLD_AGENT_MAX_PLAN_ENTRIES', 100))),

    /*
    |--------------------------------------------------------------------------
    | Editor entry-creation limit (non-super users)
    |--------------------------------------------------------------------------
    |
    | When enabled (default), only super users can create/update multiple
    | entries in a single BOLD agent request. Everyone else is capped to a
    | SINGLE entry per request — editors can still use the agent, but a prompt
    | like "create every page of this website" can never fan out into a bulk
    | run for a non-admin. Super users keep the full bold_agent_max_plan_entries
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
        'timeout' => max(5, (int) env('STATAMIC_AI_ASSISTANT_JINA_TIMEOUT', 120)),
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
            \BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher::DEFAULT_REMOVE_SELECTOR,
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
        'folder' => env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_FOLDER', 'bold-agent-fetched'),

        // Asset container that downloaded images go into when the blueprint
        // does not dictate one. If null, the first available container is used.
        // Set to the handle (e.g. "images") of the container your blueprints'
        // assets fields point at.
        'asset_container' => env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_ASSET_CONTAINER', null),

        // Per-entry caps so a runaway generation can't flood the disk.
        'max_images' => max(0, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_MAX', 30)),
        'max_bytes' => max(1024, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_MAX_BYTES', 10 * 1024 * 1024)),
        'timeout' => max(1, (int) env('STATAMIC_AI_ASSISTANT_IMAGE_FETCH_TIMEOUT', 20)),
    ],

];
