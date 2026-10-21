<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\YruPassportController;
use App\Http\Controllers\ConsoleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/console');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:auth');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/yru/redirect', [YruPassportController::class, 'redirect'])->name('yru.redirect');
    Route::get('/auth/yru/callback', [YruPassportController::class, 'callback'])->name('yru.callback');
});

// Admin test console.
Route::middleware(['auth', 'can:admin'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'index'])->name('console');
    Route::get('/status', [ConsoleController::class, 'status'])->name('console.status');
    Route::get('/logs', [ConsoleController::class, 'logs'])->name('console.logs');
});
