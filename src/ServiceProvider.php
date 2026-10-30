<?php

namespace MotionWork\StatamicAiAssistant;

use MotionWork\StatamicAiAssistant\Actions\TranslateEntry;
use MotionWork\StatamicAiAssistant\Controllers\PromptController;
use MotionWork\StatamicAiAssistant\Controllers\TranslationController;
use MotionWork\StatamicAiAssistant\Fieldtypes\AiText;
use MotionWork\StatamicAiAssistant\Fieldtypes\AiTextarea;
use MotionWork\StatamicAiAssistant\Fieldtypes\TranslationActionPreflight;
use MotionWork\StatamicAiAssistant\Fieldtypes\TranslationTargetLanguages;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
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
use MotionWork\StatamicAiAssistant\Services\GroqService;
use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Services\LlmTranslator;
use MotionWork\StatamicAiAssistant\Services\NavigationTreeSyncService;
use MotionWork\StatamicAiAssistant\Services\AssetImageDownloader;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Services\RemoteImageFetcher;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Services\TranslationGlossaryService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Services\TranslationStyleRulesService;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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

    protected $viewNamespace = 'statamic-ai-assistant';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AbstractAiService::class, function () {
            $provider = config('statamic-ai-assistant.provider_name');

            return $provider === 'infomaniak' ? new InfomaniakService() : new GroqService();
        });

        $this->app->singleton(DeeplService::class, function () {
            return new DeeplService();
        });

        $this->app->singleton(EntryReferenceResolver::class, function () {
            return new EntryReferenceResolver();
        });

        $this->app->singleton(EntryTranslator::class, function ($app) {
            return new EntryTranslator(
                $app->make(DeeplService::class),
                $app->make(EntryReferenceResolver::class),
            );
        });

        $this->app->singleton(TranslationService::class, function ($app) {
            return new TranslationService(
                $app->make(EntryTranslator::class),
                $app->make(\MotionWork\StatamicAiAssistant\Services\TermTranslator::class),
            );
        });

        $this->app->singleton(TranslationGlossaryService::class, function ($app) {
            return new TranslationGlossaryService($app->make(DeeplService::class));
        });

        $this->app->singleton(TranslationStyleRulesService::class, function ($app) {
            return new TranslationStyleRulesService($app->make(DeeplService::class));
        });

        $this->app->singleton(LlmTranslator::class, function ($app) {
            return new LlmTranslator(
                $app->make(AbstractAiService::class),
                $app->make(TranslationGlossaryService::class),
                $app->make(TranslationStyleRulesService::class),
            );
        });

        $this->app->singleton(EntryGeneratorAssetResolver::class, function () {
            return new EntryGeneratorAssetResolver;
        });

        $this->app->singleton(EntryGeneratorLinkFallback::class, function () {
            return new EntryGeneratorLinkFallback;
        });

        $this->app->singleton(PromptUrlFetcher::class, function () {
            return new PromptUrlFetcher;
        });

        $this->app->singleton(\MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator::class, function ($app) {
            return new \MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator(
                $app->make(AbstractAiService::class),
            );
        });

        $this->app->singleton(AssetImageDownloader::class, function ($app) {
            return new AssetImageDownloader(
                $app->make(\MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator::class),
            );
        });

        $this->app->singleton(RemoteImageFetcher::class, function ($app) {
            return new RemoteImageFetcher(
                $app->make(AssetImageDownloader::class),
            );
        });

        $this->app->singleton(FigmaOAuthService::class, function () {
            return new FigmaOAuthService;
        });

        $this->app->singleton(FigmaTokenStore::class, function () {
            return new FigmaTokenStore;
        });

        $this->app->singleton(FigmaContentFetcher::class, function ($app) {
            return new FigmaContentFetcher(
                $app->make(FigmaOAuthService::class),
                $app->make(FigmaTokenStore::class),
            );
        });

        $this->app->singleton(SetHintsService::class, function () {
            return new SetHintsService;
        });

        $this->app->singleton(EditorialGuidanceService::class, function ($app) {
            return new EditorialGuidanceService(
                $app->make(TranslationGlossaryService::class),
                $app->make(TranslationStyleRulesService::class),
                $app->make(DeeplService::class),
            );
        });

        $this->app->singleton(EntryGeneratorService::class, function ($app) {
            return new EntryGeneratorService(
                $app->make(AbstractAiService::class),
                $app->make(EntryGeneratorAssetResolver::class),
                $app->make(EntryGeneratorLinkFallback::class),
                $app->make(PromptUrlFetcher::class),
                $app->make(SetHintsService::class),
                $app->make(FigmaContentFetcher::class),
                $app->make(RemoteImageFetcher::class),
                editorialGuidance: $app->make(EditorialGuidanceService::class),
            );
        });

        $this->app->singleton(EntryGenerationPlanner::class, function ($app) {
            return new EntryGenerationPlanner(
                $app->make(AbstractAiService::class),
                $app->make(EntryGeneratorService::class),
                $app->make(PromptUrlFetcher::class),
                $app->make(FigmaContentFetcher::class),
                $app->make(EntryGenerationBatchService::class),
                $app->make(\MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator::class),
            );
        });

        $this->app->singleton(NavigationTreeSyncService::class, function ($app) {
            return new NavigationTreeSyncService(
                $app->make(TranslationService::class),
                $app->make(DeeplService::class),
            );
        });

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

        // Native Gate abilities backing the dashboard-managed access config, so
        // nav ->can(...) and route can: middleware enforce it. Super users pass
        // via AgentAccess::allows() itself.
        foreach (AgentAccess::FEATURES as $feature) {
            Gate::define(AgentAccess::gateAbility($feature), fn () => AgentAccess::allows($feature));
        }
        // Managing the access config itself is always super-only.
        Gate::define(AgentAccess::manageGateAbility(), fn () => AgentAccess::canManage());

        GlossaryAccess::register();

        // Legacy prompt routes
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

        // Everything this addon adds to the CP nav lives in one "AI assistant"
        // section instead of being spread across Tools and Settings.
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
        // terminology and tone applied across all translation paths.
        if (config('statamic-ai-assistant.translations', true)) {
            Nav::extend(function ($nav) use ($section) {
                if (! CpFeatures::translationsEnabled()) {
                    return;
                }

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
                if (! CpFeatures::aiEnabled()) {
                    return;
                }

                $nav->create(__('AI instructions'))
                    ->section($section())
                    ->route('statamic-ai-assistant.block-hints.page')
                    ->icon('layers-stacks')
                    ->can(AgentAccess::gateAbility('agent_settings'));
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

        Statamic::provideToScript([
            'translationsActiv' => fn () => CpFeatures::translationsEnabled(),
            'aiFeaturesEnabled' => fn () => CpFeatures::aiEnabled(),
            // Closures are resolved per CP request (User::current() is null at boot).
            'entryGeneratorEnabled' => fn () => $this->agentEnabledForCurrentUser(),
            'entryCreationMax' => fn () => \MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy::maxPlanEntries(),
            'entryCreationLimited' => fn () => \MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy::appliesTo(),
        ]);
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
