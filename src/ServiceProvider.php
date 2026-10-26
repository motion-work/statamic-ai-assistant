<?php

namespace BoldWeb\StatamicAiAssistant;

use BoldWeb\StatamicAiAssistant\Actions\TranslateEntry;
use BoldWeb\StatamicAiAssistant\Controllers\PromptController;
use BoldWeb\StatamicAiAssistant\Controllers\TranslationController;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiText;
use BoldWeb\StatamicAiAssistant\Fieldtypes\AiTextarea;
use BoldWeb\StatamicAiAssistant\Fieldtypes\TranslationActionPreflight;
use BoldWeb\StatamicAiAssistant\Fieldtypes\TranslationTargetLanguages;
use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationPlanner;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorAssetResolver;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorLinkFallback;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\EntryReferenceResolver;
use BoldWeb\StatamicAiAssistant\Services\EntryTranslator;
use BoldWeb\StatamicAiAssistant\Services\FigmaContentFetcher;
use BoldWeb\StatamicAiAssistant\Services\FigmaOAuthService;
use BoldWeb\StatamicAiAssistant\Services\FigmaTokenStore;
use BoldWeb\StatamicAiAssistant\Services\GroqService;
use BoldWeb\StatamicAiAssistant\Services\InfomaniakService;
use BoldWeb\StatamicAiAssistant\Services\NavigationTreeSyncService;
use BoldWeb\StatamicAiAssistant\Services\AssetImageDownloader;
use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use BoldWeb\StatamicAiAssistant\Services\RemoteImageFetcher;
use BoldWeb\StatamicAiAssistant\Services\SetHintsService;
use BoldWeb\StatamicAiAssistant\Services\TranslationGlossaryService;
use BoldWeb\StatamicAiAssistant\Services\TranslationService;
use BoldWeb\StatamicAiAssistant\Services\TranslationStyleRulesService;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;
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
            return new TranslationService($app->make(EntryTranslator::class));
        });

        $this->app->singleton(TranslationGlossaryService::class, function ($app) {
            return new TranslationGlossaryService($app->make(DeeplService::class));
        });

        $this->app->singleton(TranslationStyleRulesService::class, function ($app) {
            return new TranslationStyleRulesService($app->make(DeeplService::class));
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

        $this->app->singleton(AssetImageDownloader::class, function () {
            return new AssetImageDownloader;
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

        $this->app->singleton(EntryGeneratorService::class, function ($app) {
            return new EntryGeneratorService(
                $app->make(AbstractAiService::class),
                $app->make(EntryGeneratorAssetResolver::class),
                $app->make(EntryGeneratorLinkFallback::class),
                $app->make(PromptUrlFetcher::class),
                $app->make(SetHintsService::class),
                $app->make(FigmaContentFetcher::class),
                $app->make(RemoteImageFetcher::class),
            );
        });

        $this->app->singleton(EntryGenerationPlanner::class, function ($app) {
            return new EntryGenerationPlanner(
                $app->make(AbstractAiService::class),
                $app->make(EntryGeneratorService::class),
                $app->make(PromptUrlFetcher::class),
                $app->make(FigmaContentFetcher::class),
                $app->make(EntryGenerationBatchService::class),
                $app->make(\BoldWeb\StatamicAiAssistant\Support\PlanEntryDecorator::class),
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

        if (config('statamic-ai-assistant.translations', true)
            && config('statamic-ai-assistant.bulk_translations', true)) {
            Nav::extend(function ($nav) {
                if (! User::current()?->isSuper()) {
                    return;
                }

                $nav->tools(__('Bulk translations'))
                    ->route('statamic-ai-assistant.translations')
                    ->icon('globe-world-wide-web');
            });
        }

        // Glossary & style rules: visible to EVERY CP user — editors own the
        // terminology and tone that DeepL applies across all translation paths.
        if (config('statamic-ai-assistant.translations', true)) {
            Nav::extend(function ($nav) {
                $nav->tools(__('Glossary & style rules'))
                    ->route('statamic-ai-assistant.glossary.page')
                    ->icon('dictionary-language-book');
            });
        }

        if (config('statamic-ai-assistant.entry_generator', true)
            && config('statamic-ai-assistant.bold_agent_settings_nav', true)) {
            Nav::extend(function ($nav) {
                if (! User::current()?->isSuper()) {
                    return;
                }

                if (! $this->agentEnabledForCurrentUser()) {
                    return;
                }

                $nav->settings(__('BOLD agent settings'))
                    ->route('statamic-ai-assistant.block-hints.page')
                    ->icon('layers-stacks');
            });
        }

        Statamic::provideToScript([
            'translationsActiv' => config('statamic-ai-assistant.translations'),
            // Closures are resolved per CP request (User::current() is null at boot).
            'entryGeneratorEnabled' => fn () => $this->agentEnabledForCurrentUser(),
            'entryCreationMax' => fn () => \BoldWeb\StatamicAiAssistant\Support\EntryCreationPolicy::maxPlanEntries(),
            'entryCreationLimited' => fn () => \BoldWeb\StatamicAiAssistant\Support\EntryCreationPolicy::appliesTo(),
        ]);
    }

    /**
     * Whether the BOLD agent (floating button + generator) is available to the
     * current CP user. Super admins always have access when the master switch
     * is on; non-super editors additionally require enable_agent_for_editors.
     */
    protected function agentEnabledForCurrentUser(): bool
    {
        if (! config('statamic-ai-assistant.entry_generator', true)) {
            return false;
        }

        if (User::current()?->isSuper()) {
            return true;
        }

        return (bool) config('statamic-ai-assistant.enable_agent_for_editors', true);
    }
}
