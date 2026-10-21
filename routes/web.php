<?php

use App\Http\Controllers\Auth\YruPassportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/yru/redirect', [YruPassportController::class, 'redirect'])->name('yru.redirect');
    Route::get('/auth/yru/callback', [YruPassportController::class, 'callback'])->name('yru.callback');
});
