<?php

use MotionWork\StatamicAiAssistant\Http\Controllers\McpController;
use MotionWork\StatamicAiAssistant\Http\Controllers\McpOAuthController;
use Illuminate\Support\Facades\Route;

$csrf = [
    'App\Http\Middleware\VerifyCsrfToken',
    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
];

Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-protected-resource/mcp', [McpOAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [McpOAuthController::class, 'authorizationServer']);

Route::post('/mcp/oauth/register', [McpOAuthController::class, 'register'])
    ->withoutMiddleware($csrf)
    ->middleware('throttle:30,1');

Route::get('/mcp/oauth/authorize', [McpOAuthController::class, 'authorizeForm']);
Route::post('/mcp/oauth/authorize', [McpOAuthController::class, 'authorizeSubmit'])
    ->middleware('throttle:10,1');

Route::post('/mcp/oauth/token', [McpOAuthController::class, 'token'])
    ->withoutMiddleware($csrf)
    ->middleware('throttle:30,1');

Route::match(['get', 'post', 'delete'], '/mcp', [McpController::class, 'handle'])
    ->withoutMiddleware($csrf)
    ->middleware('throttle:120,1');
