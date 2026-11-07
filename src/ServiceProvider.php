<?php

namespace MotionWork\StatamicAiAssistant;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use MotionWork\StatamicAiAssistant\Actions\TranslateEntry;
use MotionWork\StatamicAiAssistant\Console\EmbeddingsCommand;
use MotionWork\StatamicAiAssistant\Console\ServiceTokenCommand;
use MotionWork\StatamicAiAssistant\Controllers\PromptController;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Fieldtypes\AiText;
use MotionWork\StatamicAiAssistant\Fieldtypes\AiTextarea;
use MotionWork\StatamicAiAssistant\Fieldtypes\TranslationActionPreflight;
use MotionWork\StatamicAiAssistant\Fieldtypes\TranslationTargetLanguages;
use MotionWork\StatamicAiAssistant\Mcp\ClaudeTranslationBridge;
use MotionWork\StatamicAiAssistant\Mcp\ContentCommitter;
use MotionWork\StatamicAiAssistant\Mcp\ImageUploads;
use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use MotionWork\StatamicAiAssistant\Mcp\ServiceTokens;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Semantic\SyncEntryEmbedding;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\AssetImageDownloader;
use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\EditorialGuidanceService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationPlanner;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorAssetResolver;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorLinkFallback;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntryReferenceResolver;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\FigmaContentFetcher;
use MotionWork\StatamicAiAssistant\Services\FigmaOAuthService;
use MotionWork\StatamicAiAssistant\Services\FigmaTokenStore;
use MotionWork\StatamicAiAssistant\Services\GeneratedImageStore;
use MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator;
use MotionWork\StatamicAiAssistant\Services\LlmTranslator;
use MotionWork\StatamicAiAssistant\Services\NavigationTreeSyncService;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Services\RemoteImageFetcher;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use MotionWork\StatamicAiAssistant\Support\PackageInstallers;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Support\WebSearch;
use MotionWork\StatamicAiAssistant\Usage\RecordAiUsage;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/app.js',
            'resources/css/app.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $fieldtypes = [
        AiTextarea::class,
        AiText::class,
        TranslationActionPreflight::class,
        TranslationTargetLanguages::class,
    ];

    protected $actions = [
        TranslateEntry::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $listen = [
        // Every finished AI call lands in the usage log ("AI usage" CP page).
        AiCompletionFinished::class => [RecordAiUsage::class],
        // Saved or deleted entries update the semantic search index.
        EntrySaved::class => [SyncEntryEmbedding::class],
        EntryDeleted::class => [SyncEntryEmbedding::class],
    ];

    protected $commands = [
        EmbeddingsCommand::class,
        ServiceTokenCommand::class,
    ];

    protected $viewNamespace = 'statamic-ai-assistant';

    /**
     * Shared per request, built by the container from their constructors.
     * Sharing matters: EntryReferenceResolver carries a run's state between
     * the translator and its callers, and the generator's field catalog is
     * read once per request.
     */
    private const SINGLETONS = [
        // DeepL and the translation pipeline (entries, terms, navigation).
        DeeplService::class,
        EntryReferenceResolver::class,
        EntryTranslator::class,
        TranslationService::class,
        TranslationGlossaryService::class,
        TranslationStyleRulesService::class,
        LlmTranslator::class,
        NavigationTreeSyncService::class,
        // The AI agent: set hints, the entry generator and planner.
        SetHintsService::class,
        EditorialGuidanceService::class,
        EntryGeneratorService::class,
        EntryGenerationPlanner::class,
        // What the agent reads besides the prompt: URLs, images, assets, links, Figma.
        EntryGeneratorAssetResolver::class,
        EntryGeneratorLinkFallback::class,
        PromptUrlFetcher::class,
        ImageAltTextGenerator::class,
        AssetImageDownloader::class,
        RemoteImageFetcher::class,
        GeneratedImageStore::class,
        FigmaOAuthService::class,
        FigmaTokenStore::class,
        FigmaContentFetcher::class,
        // Semantic search: availability and the embedding index.
        SemanticSearch::class,
        EmbeddingIndexer::class,
        // The Claude connector at /mcp.
        McpOAuth::class,
        ImageUploads::class,
        ContentCommitter::class,
        ServiceTokens::class,
        // Content package installers (install_package); packages register into it.
        PackageInstallers::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AbstractAiService::class, fn () => AiProviders::make());

        foreach (self::SINGLETONS as $class) {
            $this->app->singleton($class);
        }

        // Not singletons, but bound: the container passes null for an optional
        // constructor parameter whose class is not bound (the planner's).
        $this->app->bind(EntryGenerationBatchService::class);
        $this->app->bind(PlanEntryDecorator::class);

        // Not singletons: the bridge holds the signed-in actor.
        $this->app->bind(ClaudeTranslationBridge::class);
        $this->app->bind(TranslationMcpServer::class);
    }

    public function bootAddon()
    {
        $this->registerWebRoutes(__DIR__.'/../routes/web.php');

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-ai-assistant');

        $this->mergeConfigFrom(
            __DIR__.'/../config/statamic-ai-assistant.php',
            'statamic-ai-assistant'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/deepl.php',
            'deepl'
        );

        $this->publishes([
            __DIR__.'/../config/statamic-ai-assistant.php' => config_path('statamic-ai-assistant.php'),
            __DIR__.'/../config/deepl.php' => config_path('deepl.php'),
        ], 'statamic-ai-assistant-config');

        $this->defineAccessGates();

        $this->registerLegacyPromptRoutes();

        $this->extendCpNav();

        Statamic::provideToScript([
            'translationsActiv' => fn () => CpFeatures::translationsEnabled(),
            'aiFeaturesEnabled' => fn () => CpFeatures::aiEnabled(),
            // Closures are resolved per CP request (User::current() is null at boot).
            'entryGeneratorEnabled' => fn () => $this->agentEnabledForCurrentUser(),
            'entryCreationMax' => fn () => EntryCreationPolicy::maxPlanEntries(),
            'entryCreationLimited' => fn () => EntryCreationPolicy::appliesTo(),
            // Whether the agent composer offers the per-message web search toggle.
            'agentWebSearchAvailable' => fn () => WebSearch::available(),
        ]);
    }

    private function defineAccessGates(): void
    {
        // Native Gate abilities backing the dashboard-managed access config, so
        // nav ->can(...) and route can: middleware enforce it. Super users pass
        // via AgentAccess::allows() itself.
        foreach (AgentAccess::FEATURES as $feature) {
            Gate::define(AgentAccess::gateAbility($feature), fn () => AgentAccess::allows($feature));
        }
        // Managing the access config itself is always super-only.
        Gate::define(AgentAccess::manageGateAbility(), fn () => AgentAccess::canManage());

        GlossaryAccess::register();
    }

    /** CP routes the Bard and text-field AI buttons have always called. */
    private function registerLegacyPromptRoutes(): void
    {
        $this->registerCpRoutes(function () {
            Route::post('/prompt', [PromptController::class, 'handle']);
        });

        $this->registerCpRoutes(function () {
            Route::post('/promptHtmlrefactor', [PromptController::class, 'handleHtmlRefactor']);
        });

        $this->registerCpRoutes(function () {
            Route::post('/promptrefactor', [PromptController::class, 'handleRefactor']);
        });

        $this->registerCpRoutes(function () {
            Route::get('/getLocalizations', [PromptController::class, 'getLocalizations']);
        });
    }

    /**
     * Everything this addon adds to the CP nav lives in one "AI assistant"
     * section instead of being spread across Tools and Settings.
     */
    private function extendCpNav(): void
    {
        $section = fn () => __('AI assistant');

        if (config('statamic-ai-assistant.translations', true)
            && config('statamic-ai-assistant.bulk_translations', true)) {
            Nav::extend(function ($nav) use ($section) {
                if (! CpFeatures::translationsEnabled()) {
                    return;
                }

                $nav->create(__('Bulk translations'))
                    ->section($section())
                    ->route('statamic-ai-assistant.translations')
                    ->icon('globe-world-wide-web')
                    ->can(AgentAccess::gateAbility('bulk_translations'));
            });
        }

        // Glossary & style rules: visible to EVERY CP user — editors own the
        // terminology and tone applied across all translation paths. Shown
        // without DeepL or an AI model too, since Claude uses them.
        if (CpFeatures::glossaryEnabled()) {
            Nav::extend(function ($nav) use ($section) {
                $nav->create(__('Glossary & style rules'))
                    ->section($section())
                    ->route('statamic-ai-assistant.glossary.page')
                    ->icon('dictionary-language-book');
            });
        }

        // Site instructions, block and field hints, Figma: used by the AI agent
        // and by the AI buttons on fields.
        if (config('statamic-ai-assistant.entry_generator', true)
            && config('statamic-ai-assistant.agent_settings_nav', true)) {
            Nav::extend(function ($nav) use ($section) {
                if (! CpFeatures::aiInstructionsEnabled()) {
                    return;
                }

                $nav->create(__('AI instructions'))
                    ->section($section())
                    ->route('statamic-ai-assistant.block-hints.page')
                    ->icon('layers-stacks')
                    ->can(AgentAccess::gateAbility('agent_settings'));
            });
        }

        // "AI usage" — what the AI calls cost, per task, model and person.
        // Super-admins only, like the access page.
        if (config('statamic-ai-assistant.usage_tracking', true)) {
            Nav::extend(function ($nav) use ($section) {
                if (! CpFeatures::aiEnabled()) {
                    return;
                }

                $nav->create(__('AI usage'))
                    ->section($section())
                    ->route('statamic-ai-assistant.usage.page')
                    ->icon('chart-monitoring-indicator')
                    ->can(AgentAccess::manageGateAbility());
            });
        }

        // "AI & Claude access" — super-admins only. Always listed: the Claude
        // connector has its own access levels even without AI features.
        Nav::extend(function ($nav) use ($section) {
            $nav->create(__('AI & Claude access'))
                ->section($section())
                ->route('statamic-ai-assistant.access.page')
                ->icon('permissions')
                ->can(AgentAccess::manageGateAbility());
        });
    }

    /**
     * Whether the AI agent (floating button + generator) is available to the
     * current CP user. Gated globally by the `entry_generator` master switch,
     * then by the dashboard-managed access config (super admins always pass).
     */
    protected function agentEnabledForCurrentUser(): bool
    {
        if (! config('statamic-ai-assistant.entry_generator', true) || ! CpFeatures::aiEnabled()) {
            return false;
        }

        return AgentAccess::allows('agent');
    }
}
