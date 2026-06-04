<?php

declare(strict_types=1);

use App\Http\Controllers\AksesoriController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\CardReplayController;
use App\Http\Controllers\Api\CardSeenController;
use App\Http\Controllers\Auth\DemoAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RekorController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\Strava\StravaWebhookController;
use App\Http\Controllers\Strava\SyncController;
use App\Http\Controllers\TokenUsageController;
use Illuminate\Support\Facades\Route;

// Strava push subscription. Called by Strava unauthenticated — gated by the
// shared verify token (handshake) and scoped to the owning athlete (events),
// so it lives outside the auth middleware group.
Route::get('/strava/webhook', [StravaWebhookController::class, 'verify'])->name('strava.webhook.verify');
Route::post('/strava/webhook', [StravaWebhookController::class, 'handle'])->name('strava.webhook.handle');

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

    Route::get('/kalender', CalendarController::class)->name('kalender');

    Route::get('/kartu', [CardController::class, 'index'])->name('kartu.index');
    Route::get('/kartu/{card}', [CardController::class, 'show'])->name('kartu.show');

    // Catatan merged into Aktivitas — keep deep links working.
    Route::permanentRedirect('/catatan', '/aktivitas');

    Route::get('/rekor', RekorController::class)->name('rekor');

    Route::get('/aksesori', [AksesoriController::class, 'index'])->name('aksesori');
    Route::post('/api/aksesori/equip', [AksesoriController::class, 'equip'])
        ->name('api.aksesori.equip');

    Route::get('/profil', ProfileController::class)->name('profil');

    Route::post('/strava/sync', SyncController::class)
        ->middleware('throttle:strava-sync')
        ->name('strava.sync');

    Route::post('/logout', [StravaAuthController::class, 'logout'])->name('auth.logout');

    // Legacy 301 redirects — keep deep links working from external bookmarks.
    Route::permanentRedirect('/runs', '/aktivitas');
    Route::redirect('/runs/{activity}', '/aktivitas/{activity}', 301);
    Route::permanentRedirect('/cards', '/kartu');
    Route::permanentRedirect('/progress', '/aktivitas');
    Route::permanentRedirect('/settings', '/profil');
    Route::permanentRedirect('/pengaturan', '/profil');
    Route::permanentRedirect('/profile', '/profil');

    Route::post('/api/milestones/{activity}/dismiss', [MilestoneController::class, 'dismiss'])
        ->name('api.milestones.dismiss');

    Route::post('/api/kartu/{card}/seen', CardSeenController::class)
        ->name('api.kartu.seen');
    Route::post('/api/kartu/{card}/replay', CardReplayController::class)
        ->name('api.kartu.replay');

    Route::get('/api/analyses/{type}/{subjectId}', [AnalysisController::class, 'show'])
        ->whereNumber('subjectId')
        ->name('api.analyses.show');
    Route::post('/api/analyses/{type}/{subjectId}/trigger', [AnalysisController::class, 'trigger'])
        ->whereNumber('subjectId')
        ->middleware('throttle:analysis-trigger')
        ->name('api.analyses.trigger');

});

// Edge basicauth (docker/Caddyfile) protects this in prod. No `auth` middleware
// so ops can open it without a Strava session.
Route::get('/ai-usage', [TokenUsageController::class, 'show'])->name('ai-usage');
