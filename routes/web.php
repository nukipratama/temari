<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\DemoAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\RunController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::get('/auth/strava/redirect', [StravaAuthController::class, 'redirect'])->name('auth.strava.redirect');
    Route::get('/auth/strava/callback', [StravaAuthController::class, 'callback'])->name('auth.strava.callback');
    Route::post('/auth/demo', [DemoAuthController::class, 'login'])->name('auth.demo');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{activity}', [RunController::class, 'show'])->name('runs.show');
    Route::get('/cards', [CardController::class, 'index'])->name('cards.index');
    Route::get('/progress', ProgressController::class)->name('progress');
    Route::post('/logout', [StravaAuthController::class, 'logout'])->name('auth.logout');
});
