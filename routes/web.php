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
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RekorController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\RunnerZonesController;
use App\Http\Controllers\Strava\ResyncActivityController;
use App\Http\Controllers\Strava\StravaWebhookController;
use App\Http\Controllers\Strava\SyncController;
use App\Http\Controllers\Telegram\SendActivityNotificationController;
use App\Http\Controllers\Telegram\SendMonthlyRecapNotificationController;
use App\Http\Controllers\Telegram\SendWeeklyRecapNotificationController;
use App\Http\Controllers\Telegram\TelegramConnectionController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\TokenUsageController;
use Illuminate\Support\Facades\Route;

// Strava push subscription. Called by Strava unauthenticated — gated by the
// shared verify token (handshake) and scoped to the owning athlete (events),
// so it lives outside the auth middleware group.
Route::get('/strava/webhook', [StravaWebhookController::class, 'verify'])->name('strava.webhook.verify');
// IP rate-limited like the other public POSTs: the channel is unauthenticated,
// so cap it to blunt amplification. 60/min is well above Strava's real delivery
// rate (one event per activity) while still throttling a flood.
Route::post('/strava/webhook', [StravaWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('strava.webhook.handle');

// Telegram bot webhook. Called by Telegram unauthenticated — gated by the secret
// token echoed in the X-Telegram-Bot-Api-Secret-Token header. IP rate-limited
// like the other public POSTs.
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('telegram.webhook.handle');

// Client-side error sink. Unauthenticated so it captures errors on guest pages
// (e.g. /login) too; CSRF-exempt + IP rate-limited (low-risk telemetry).
Route::post('/client-errors', ClientErrorController::class)
    ->middleware('throttle:client-errors')
    ->name('client-errors');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/auth/demo', [DemoAuthController::class, 'login'])->name('auth.demo');
});

// Strava OAuth is reachable by guests (first connect) AND authenticated users
// (reconnect to grant a newly added scope, e.g. the StravaZoneReconnectBanner).
// The callback upserts by athlete id, so a logged-in user re-authing just refreshes
// their existing connection. Keeping these out of the `guest` group is what lets the
// reconnect flow reach the redirect/callback at all instead of bouncing to dashboard.
Route::get('/auth/strava/redirect', [StravaAuthController::class, 'redirect'])->name('auth.strava.redirect');
Route::get('/auth/strava/callback', [StravaAuthController::class, 'callback'])->name('auth.strava.callback');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/aktivitas', [RunController::class, 'index'])->name('aktivitas.index');
    Route::get('/aktivitas/{activity}', [RunController::class, 'show'])->name('aktivitas.show');
    Route::post('/aktivitas/{activity}/resync', ResyncActivityController::class)
        ->middleware('throttle:strava-sync')
        ->name('aktivitas.resync');
    Route::post('/aktivitas/{activity}/telegram', SendActivityNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('aktivitas.telegram');

    Route::get('/kalender', CalendarController::class)->name('kalender');

    Route::post('/rekap-mingguan/{snapshot}/telegram', SendWeeklyRecapNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('rekap.mingguan.telegram');
    Route::post('/rekap-bulanan/{month}/telegram', SendMonthlyRecapNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('rekap.bulanan.telegram');

    Route::get('/kartu', [CardController::class, 'index'])->name('kartu.index');

    // Catatan merged into Aktivitas — keep deep links working.
    Route::permanentRedirect('/catatan', '/aktivitas');

    Route::get('/rekor', RekorController::class)->name('rekor');

    Route::get('/target', [GoalController::class, 'index'])->name('target');
    Route::get('/aksesori', [AksesoriController::class, 'index'])->name('aksesori');
    Route::post('/api/aksesori/equip', [AksesoriController::class, 'equip'])
        ->name('api.aksesori.equip');

    Route::get('/profil', ProfileController::class)->name('profil');

    // The demo is otherwise a fully-interactive shared sandbox (drift resets on
    // demo:seed). Telegram is the one write worth guarding: a visitor could disconnect
    // the shared bot or spam real messages via the send/test endpoints. So the demo
    // write-guard is applied to Telegram routes only, not blanket.
    Route::patch('/profil/telegram', [TelegramConnectionController::class, 'update'])->middleware('block-demo-telegram')->name('telegram.preferences.update');
    Route::delete('/profil/telegram', [TelegramConnectionController::class, 'destroy'])->middleware('block-demo-telegram')->name('telegram.disconnect');
    Route::post('/profil/telegram/test', [TelegramConnectionController::class, 'test'])->middleware('block-demo-telegram')->name('telegram.test');

    Route::get('/pengaturan/zona', [RunnerZonesController::class, 'index'])->name('pengaturan.zona');
    Route::patch('/pengaturan/zona', [RunnerZonesController::class, 'update'])->name('pengaturan.zona.update');
    Route::delete('/pengaturan/zona', [RunnerZonesController::class, 'resetToDefault'])->name('pengaturan.zona.reset');
    Route::post('/pengaturan/zona/sinkron-strava', [RunnerZonesController::class, 'resyncFromStrava'])->name('pengaturan.zona.resync');

    Route::post('/strava/sync', SyncController::class)
        ->middleware('throttle:strava-sync')
        ->name('strava.sync');

    Route::post('/logout', [StravaAuthController::class, 'logout'])
        ->name('auth.logout');

    // Legacy 301 redirects — keep deep links working from external bookmarks.
    Route::permanentRedirect('/runs', '/aktivitas');
    Route::redirect('/runs/{activity}', '/aktivitas/{activity}', 301);
    Route::permanentRedirect('/cards', '/kartu');
    Route::permanentRedirect('/progress', '/aktivitas');
    Route::permanentRedirect('/settings', '/profil');
    Route::permanentRedirect('/pengaturan', '/profil');
    Route::permanentRedirect('/profile', '/profil');

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

// Gated by an authenticated maintainer session (`is_admin` per Strava account),
// which authorizes both the page and its mutating retry POST at the app layer.
// Edge basicauth (docker/Caddyfile) stays as defense-in-depth in prod.
Route::middleware(['auth', 'admin'])->group(function (): void {
    Route::get('/ai-usage', [TokenUsageController::class, 'show'])->name('ai-usage');
    Route::post('/ai-usage/recover', [TokenUsageController::class, 'recover'])->name('ai-usage.recover');
    Route::post('/ai-usage/users/{userId}/retry-failed', [TokenUsageController::class, 'retryFailed'])
        ->whereNumber('userId')
        ->name('ai-usage.retry-failed');
});
