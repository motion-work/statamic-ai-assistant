<?php

use MotionWork\StatamicAiAssistant\Controllers\AgentAccessController;
use MotionWork\StatamicAiAssistant\Controllers\EntryGeneratorController;
use MotionWork\StatamicAiAssistant\Controllers\FigmaOAuthController;
use MotionWork\StatamicAiAssistant\Controllers\SetHintsController;
use MotionWork\StatamicAiAssistant\Controllers\TranslationController;
use MotionWork\StatamicAiAssistant\Controllers\TranslationGlossaryController;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('ai-generate')->name('statamic-ai-assistant.generate.')
    ->middleware('can:'.AgentAccess::gateAbility('agent'))
    ->group(function () {
    Route::get('/', function () {
        return view('statamic-ai-assistant::entry-generator');
    })->name('page');

    Route::get('/collections', [EntryGeneratorController::class, 'collections'])->name('collections');
    Route::get('/entry-search', [EntryGeneratorController::class, 'entrySearch'])->name('entry-search');
    Route::get('/open-entry', [EntryGeneratorController::class, 'openEntry'])->name('open-entry');
    Route::get('/asset-preview', [EntryGeneratorController::class, 'assetPreview'])->name('asset-preview');
    Route::get('/asset-browser', [EntryGeneratorController::class, 'assetBrowser'])->name('asset-browser');
    Route::get('/blueprint-fields', [EntryGeneratorController::class, 'blueprintFields'])->name('blueprint-fields');
    Route::post('/generate', [EntryGeneratorController::class, 'generate'])->name('generate');
    Route::post('/generate-stream', [EntryGeneratorController::class, 'generateStream'])->name('generate-stream');
    Route::get('/generate-progress/{sessionId}', [EntryGeneratorController::class, 'generateBatchProgress'])->name('generate-progress');
    Route::post('/generate-cancel/{sessionId}', [EntryGeneratorController::class, 'generateBatchCancel'])->name('generate-cancel');
    Route::post('/generate-continue/{sessionId}', [EntryGeneratorController::class, 'generateContinue'])->name('generate-continue');
    Route::post('/generate-resume', [EntryGeneratorController::class, 'generateResume'])->name('generate-resume');
    Route::get('/advanced-tools', [EntryGeneratorController::class, 'advancedToolsPreference'])->name('advanced-tools');
    Route::post('/advanced-tools', [EntryGeneratorController::class, 'saveAdvancedToolsPreference'])->name('advanced-tools.save');
    Route::post('/create-entry', [EntryGeneratorController::class, 'createEntry'])->name('create-entry');
    Route::post('/regenerate-field', [EntryGeneratorController::class, 'regenerateField'])->name('regenerate-field');
});

Route::prefix('ai-block-hints')->name('statamic-ai-assistant.block-hints.')
    ->middleware('can:'.AgentAccess::gateAbility('agent_settings'))
    ->group(function () {
        Route::get('/', function () {
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
        Route::get('/', function () {
            return view('statamic-ai-assistant::agent-access');
        })->name('page');

        Route::get('/data', [AgentAccessController::class, 'index'])->name('data');
        Route::post('/save', [AgentAccessController::class, 'save'])->name('save');
    });

Route::prefix('ai-translation-glossary')->name('statamic-ai-assistant.glossary.')->group(function () {
    Route::get('/', function () {
        if (! config('statamic-ai-assistant.translations', true)) {
            abort(404);
        }

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
            if (! config('statamic-ai-assistant.bulk_translations', true)) {
                abort(404);
            }

            return view('statamic-ai-assistant::translations');
        })->name('translations');

        Route::post('/bulk', [TranslationController::class, 'translateBulk'])->name('translate.bulk');
        Route::get('/collection-entries', [TranslationController::class, 'collectionEntries'])->name('translate.collection-entries');
        Route::get('/taxonomy-terms', [TranslationController::class, 'taxonomyTerms'])->name('translate.taxonomy-terms');
        Route::post('/term', [TranslationController::class, 'translateTerm'])->name('translate.term');
        Route::get('/navigation-entries', [TranslationController::class, 'navigationEntries'])->name('translate.navigation-entries');
        Route::post('/navigation-sync', [TranslationController::class, 'navigationSync'])->name('translate.navigation-sync');
    });
});
