<?php

use BoldWeb\StatamicAiAssistant\Controllers\EntryGeneratorController;
use BoldWeb\StatamicAiAssistant\Controllers\FigmaOAuthController;
use BoldWeb\StatamicAiAssistant\Controllers\SetHintsController;
use BoldWeb\StatamicAiAssistant\Controllers\TranslationController;
use BoldWeb\StatamicAiAssistant\Controllers\TranslationGlossaryController;
use Illuminate\Support\Facades\Route;

Route::prefix('ai-generate')->name('statamic-ai-assistant.generate.')->group(function () {
    Route::get('/', function () {
        return view('statamic-ai-assistant::entry-generator');
    })->name('page');

    Route::get('/collections', [EntryGeneratorController::class, 'collections'])->name('collections');
    Route::get('/blueprint-fields', [EntryGeneratorController::class, 'blueprintFields'])->name('blueprint-fields');
    Route::post('/generate', [EntryGeneratorController::class, 'generate'])->name('generate');
    Route::post('/generate-stream', [EntryGeneratorController::class, 'generateStream'])->name('generate-stream');
    Route::get('/generate-progress/{sessionId}', [EntryGeneratorController::class, 'generateBatchProgress'])->name('generate-progress');
    Route::post('/generate-cancel/{sessionId}', [EntryGeneratorController::class, 'generateBatchCancel'])->name('generate-cancel');
    Route::post('/create-entry', [EntryGeneratorController::class, 'createEntry'])->name('create-entry');
    Route::post('/regenerate-field', [EntryGeneratorController::class, 'regenerateField'])->name('regenerate-field');
});

Route::prefix('ai-block-hints')->name('statamic-ai-assistant.block-hints.')->group(function () {
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
    Route::get('/', function () {
        if (! config('statamic-ai-assistant.bulk_translations', true)) {
            abort(404);
        }

        return view('statamic-ai-assistant::translations');
    })->name('translations');

    Route::post('/entry', [TranslationController::class, 'translateEntry'])->name('translate.entry');
    Route::post('/bulk', [TranslationController::class, 'translateBulk'])->name('translate.bulk');
    Route::post('/conflict-check', [TranslationController::class, 'conflictCheck'])->name('translate.conflict-check');
    Route::post('/field', [TranslationController::class, 'translateField'])->name('translate.field');
    Route::get('/progress/{batchId}', [TranslationController::class, 'progress'])->name('translate.progress');
    Route::get('/status', [TranslationController::class, 'status'])->name('translate.status');
    Route::get('/deepl-usage', [TranslationController::class, 'deeplUsage'])->name('translate.deepl-usage');
    Route::get('/collection-entries', [TranslationController::class, 'collectionEntries'])->name('translate.collection-entries');
    Route::get('/navigation-entries', [TranslationController::class, 'navigationEntries'])->name('translate.navigation-entries');
    Route::post('/navigation-sync', [TranslationController::class, 'navigationSync'])->name('translate.navigation-sync');
});
