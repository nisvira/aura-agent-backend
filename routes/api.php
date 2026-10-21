<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatSessionController;
use App\Http\Controllers\Api\OpenAiController;
use Illuminate\Support\Facades\Route;

// Public: swap a YRU Passport access token for a Sanctum API token.
Route::post('/auth/yru-passport', [AuthController::class, 'exchangePassportToken'])
    ->middleware('throttle:auth');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Calls RAG + Ollama: stricter limiter on top of the general one.
    Route::middleware('throttle:chat')->group(function () {
        Route::post('/chat', [ChatController::class, 'store']);
        Route::post('/chat/stream', [ChatController::class, 'stream']);
    });

    Route::get('/chat/sessions', [ChatSessionController::class, 'index']);
    Route::get('/chat/sessions/{session}', [ChatSessionController::class, 'show'])->whereNumber('session');
    Route::patch('/chat/sessions/{session}', [ChatSessionController::class, 'update'])->whereNumber('session');
    Route::delete('/chat/sessions/{session}', [ChatSessionController::class, 'destroy'])->whereNumber('session');
});

// OpenAI-compatible API for Open WebUI. Base URL: http://<host>:8000/api/v1
Route::prefix('v1')->middleware(['openwebui.auth', 'throttle:api'])->group(function () {
    Route::get('/models', [OpenAiController::class, 'models']);
    Route::post('/chat/completions', [OpenAiController::class, 'chatCompletions'])
        ->middleware('throttle:chat')
        ->name('openai.chat');
});
