<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Auth\DemoAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CatatanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RekorController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::get('/auth/strava/redirect', [StravaAuthController::class, 'redirect'])->name('auth.strava.redirect');
    Route::get('/auth/strava/callback', [StravaAuthController::class, 'callback'])->name('auth.strava.callback');
    Route::post('/auth/demo', [DemoAuthController::class, 'login'])->name('auth.demo');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/aktivitas', [RunController::class, 'index'])->name('aktivitas.index');
    Route::get('/aktivitas/{activity}', [RunController::class, 'show'])->name('aktivitas.show');

    Route::get('/kartu', [CardController::class, 'index'])->name('kartu.index');

    Route::get('/catatan', CatatanController::class)->name('catatan');
    Route::get('/rekor', RekorController::class)->name('rekor');

    Route::get('/pengaturan', SettingsController::class)->name('pengaturan');
    Route::get('/profil', ProfileController::class)->name('profil');

    Route::post('/logout', [StravaAuthController::class, 'logout'])->name('auth.logout');

    // Legacy 301 redirects — keep deep links working from external bookmarks.
    Route::permanentRedirect('/runs', '/aktivitas');
    Route::redirect('/runs/{activity}', '/aktivitas/{activity}', 301);
    Route::permanentRedirect('/cards', '/kartu');
    Route::permanentRedirect('/progress', '/catatan');
    Route::permanentRedirect('/settings', '/pengaturan');
    Route::permanentRedirect('/profile', '/profil');

    Route::get('/api/analyses/{type}/{subjectId}', [AnalysisController::class, 'show'])
        ->whereNumber('subjectId')
        ->name('api.analyses.show');
    Route::post('/api/analyses/{type}/{subjectId}/trigger', [AnalysisController::class, 'trigger'])
        ->whereNumber('subjectId')
        ->name('api.analyses.trigger');
});
