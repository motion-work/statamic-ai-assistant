<?php

use Illuminate\Support\Facades\Route;
use MotionWork\StatamicAiAssistant\Controllers\AgentAccessController;
use MotionWork\StatamicAiAssistant\Controllers\AgentPickerController;
use MotionWork\StatamicAiAssistant\Controllers\AgentPreferenceController;
use MotionWork\StatamicAiAssistant\Controllers\AgentSessionController;
use MotionWork\StatamicAiAssistant\Controllers\AiUsageController;
use MotionWork\StatamicAiAssistant\Controllers\BulkTranslationController;
use MotionWork\StatamicAiAssistant\Controllers\EntryGeneratorController;
use MotionWork\StatamicAiAssistant\Controllers\FigmaOAuthController;
use MotionWork\StatamicAiAssistant\Controllers\SemanticSearchController;
use MotionWork\StatamicAiAssistant\Controllers\SetHintsController;
use MotionWork\StatamicAiAssistant\Controllers\TranslationController;
use MotionWork\StatamicAiAssistant\Controllers\TranslationGlossaryController;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;

Route::prefix('ai-generate')->name('statamic-ai-assistant.generate.')
    ->middleware('can:'.AgentAccess::gateAbility('agent'))
    ->group(function () {
        Route::get('/', function () {
            abort_unless(CpFeatures::aiEnabled(), 404);

            return view('statamic-ai-assistant::entry-generator');
        })->name('page');

        Route::get('/collections', [AgentPickerController::class, 'collections'])->name('collections');
        Route::get('/entry-search', [AgentPickerController::class, 'entrySearch'])->name('entry-search');
        Route::get('/open-entry', [AgentPickerController::class, 'openEntry'])->name('open-entry');
        Route::get('/asset-preview', [AgentPickerController::class, 'assetPreview'])->name('asset-preview');
        Route::get('/asset-browser', [AgentPickerController::class, 'assetBrowser'])->name('asset-browser');
        Route::get('/blueprint-fields', [AgentPickerController::class, 'blueprintFields'])->name('blueprint-fields');
        Route::post('/generate', [EntryGeneratorController::class, 'generate'])->name('generate');
        Route::post('/generate-stream', [AgentSessionController::class, 'generateStream'])->name('generate-stream');
        Route::get('/generate-progress/{sessionId}', [AgentSessionController::class, 'generateBatchProgress'])->name('generate-progress');
        Route::post('/generate-cancel/{sessionId}', [AgentSessionController::class, 'generateBatchCancel'])->name('generate-cancel');
        Route::post('/generate-continue/{sessionId}', [AgentSessionController::class, 'generateContinue'])->name('generate-continue');
        Route::post('/generate-resume', [AgentSessionController::class, 'generateResume'])->name('generate-resume');
        Route::get('/advanced-tools', [AgentPreferenceController::class, 'advancedToolsPreference'])->name('advanced-tools');
        Route::post('/advanced-tools', [AgentPreferenceController::class, 'saveAdvancedToolsPreference'])->name('advanced-tools.save');
        Route::post('/create-entry', [EntryGeneratorController::class, 'createEntry'])->name('create-entry');
        Route::post('/regenerate-field', [EntryGeneratorController::class, 'regenerateField'])->name('regenerate-field');
    });

Route::prefix('ai-block-hints')->name('statamic-ai-assistant.block-hints.')
    ->middleware('can:'.AgentAccess::gateAbility('agent_settings'))
    ->group(function () {
        Route::get('/', function () {
            abort_unless(CpFeatures::aiEnabled(), 404);

            return view('statamic-ai-assistant::set-hints-settings');
        })->name('page');

        Route::get('/list', [SetHintsController::class, 'index'])->name('list');
        Route::post('/save', [SetHintsController::class, 'save'])->name('save');
        Route::post('/generate', [SetHintsController::class, 'generate'])->name('generate');

        Route::get('/figma/status', [FigmaOAuthController::class, 'status'])->name('figma.status');
        Route::get('/figma/connect', [FigmaOAuthController::class, 'connect'])->name('figma.connect');
        Route::get('/figma/callback', [FigmaOAuthController::class, 'callback'])->name('figma.callback');
        Route::post('/figma/disconnect', [FigmaOAuthController::class, 'disconnect'])->name('figma.disconnect');
    });

// "Who has access" — dedicated settings screen, super-admins only.
Route::prefix('ai-agent-access')->name('statamic-ai-assistant.access.')
    ->middleware('can:'.AgentAccess::manageGateAbility())
    ->group(function () {
        // Always available: the Claude connector works without AI features.
        Route::get('/', fn () => view('statamic-ai-assistant::agent-access'))->name('page');

        Route::get('/data', [AgentAccessController::class, 'index'])->name('data');
        Route::post('/save', [AgentAccessController::class, 'save'])->name('save');
    });

// "AI usage" — cost and calls per task, model and person; super-admins only.
Route::prefix('ai-usage')->name('statamic-ai-assistant.usage.')
    ->middleware('can:'.AgentAccess::manageGateAbility())
    ->group(function () {
        Route::get('/', function () {
            abort_unless(CpFeatures::aiEnabled(), 404);

            return view('statamic-ai-assistant::ai-usage');
        })->name('page');

        Route::get('/data', [AiUsageController::class, 'data'])->name('data');
        Route::get('/semantic-search', [SemanticSearchController::class, 'status'])->name('semantic');
        Route::post('/semantic-search/rebuild', [SemanticSearchController::class, 'rebuild'])->name('semantic.rebuild');
    });

Route::prefix('ai-translation-glossary')->name('statamic-ai-assistant.glossary.')->group(function () {
    Route::get('/', function () {
        // Also without DeepL or an AI model: the Claude connector uses them.
        abort_unless(CpFeatures::glossaryEnabled(), 404);

        return view('statamic-ai-assistant::translation-glossary');
    })->name('page');

    Route::get('/data', [TranslationGlossaryController::class, 'data'])->name('data');
    Route::post('/save', [TranslationGlossaryController::class, 'save'])->name('save');
});

Route::prefix('ai-translations')->name('statamic-ai-assistant.')->group(function () {
    // Field-level + page/entry-level translation stay open to every CP user.
    Route::post('/entry', [TranslationController::class, 'translateEntry'])->name('translate.entry');
    Route::post('/conflict-check', [TranslationController::class, 'conflictCheck'])->name('translate.conflict-check');
    Route::post('/field', [TranslationController::class, 'translateField'])->name('translate.field');
    Route::get('/progress/{batchId}', [TranslationController::class, 'progress'])->name('translate.progress');
    Route::get('/status', [TranslationController::class, 'status'])->name('translate.status');
    Route::get('/deepl-usage', [TranslationController::class, 'deeplUsage'])->name('translate.deepl-usage');

    // Bulk translation: the page + its bulk-only endpoints require access.
    Route::middleware('can:'.AgentAccess::gateAbility('bulk_translations'))->group(function () {
        Route::get('/', function () {
            if (! config('statamic-ai-assistant.bulk_translations', true) || ! CpFeatures::translationsEnabled()) {
                abort(404);
            }

            return view('statamic-ai-assistant::translations');
        })->name('translations');

        Route::post('/bulk', [BulkTranslationController::class, 'translateBulk'])->name('translate.bulk');
        Route::get('/collection-entries', [BulkTranslationController::class, 'collectionEntries'])->name('translate.collection-entries');
        Route::get('/taxonomy-terms', [BulkTranslationController::class, 'taxonomyTerms'])->name('translate.taxonomy-terms');
        Route::post('/term', [BulkTranslationController::class, 'translateTerm'])->name('translate.term');
        Route::get('/navigation-entries', [BulkTranslationController::class, 'navigationEntries'])->name('translate.navigation-entries');
        Route::post('/navigation-sync', [BulkTranslationController::class, 'navigationSync'])->name('translate.navigation-sync');
    });
});
