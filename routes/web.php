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
Route::post('/logout/passport', [LoginController::class, 'logoutPassport'])->middleware('auth')->name('logout.passport');

Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/yru/redirect', [YruPassportController::class, 'redirect'])->name('yru.redirect');
    Route::get('/auth/yru/callback', [YruPassportController::class, 'callback'])->name('yru.callback');
    // Same callback at the path registered for Passport client 269 (http://127.0.0.1/callback),
    // used when testing through an SSH tunnel: ssh -L 80:127.0.0.1:8000 <server>.
    Route::get('/callback', [YruPassportController::class, 'callback']);
});

// Test console: admins and instructors chat; only admins see status and logs.
Route::middleware(['auth', 'can:console'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'index'])->name('console');

    Route::middleware('can:admin')->group(function () {
        Route::get('/status', [ConsoleController::class, 'status'])->name('console.status');
        Route::get('/logs', [ConsoleController::class, 'logs'])->name('console.logs');
    });
});
