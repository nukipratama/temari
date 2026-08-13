<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AksesoriController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\CardReplayController;
use App\Http\Controllers\Api\CardSeenController;
use App\Http\Controllers\Auth\DemoAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\StravaAuthController;
use App\Http\Controllers\BadgeBoardController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevtoolsIndexController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\NotificationTestController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RekorController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\RunnerZonesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Strava\ResyncActivityController;
use App\Http\Controllers\Strava\StravaWebhookController;
use App\Http\Controllers\Strava\SyncController;
use App\Http\Controllers\Notifications\SendActivityNotificationController;
use App\Http\Controllers\Notifications\SendMonthlyRecapNotificationController;
use App\Http\Controllers\Notifications\SendWeeklyRecapNotificationController;
use App\Http\Controllers\Telegram\TelegramConnectionController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\WebPush\PushSubscriptionController;
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

// Public and unauthenticated on purpose: someone deciding whether to connect
// their Strava has to be able to read these before there is an account.
Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/ai-use', [LegalController::class, 'aiUse'])->name('legal.ai-use');
Route::get('/training-disclaimer', [LegalController::class, 'trainingDisclaimer'])->name('legal.training-disclaimer');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    // Throttled like the other public POSTs to blunt session-creation spam; it
    // only ever logs in as the shared sandboxed demo user, so the cap is generous.
    Route::post('/auth/demo', [DemoAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.demo');
});

// Strava OAuth is reachable by guests (first connect) AND authenticated users
// (reconnect to grant a newly added scope, e.g. the StravaZoneReconnectBanner).
// The callback upserts by athlete id, so a logged-in user re-authing just refreshes
// their existing connection. Keeping these out of the `guest` group is what lets the
// reconnect flow reach the redirect/callback at all instead of bouncing to dashboard.
// Throttled per IP: this is the account-creation path, open to anyone, and the
// callback spends a Strava token exchange (plus a history backfill on a first
// connect) before any session exists to key a limit by.
Route::middleware('throttle:strava-oauth')->group(function (): void {
    Route::get('/auth/strava/redirect', [StravaAuthController::class, 'redirect'])->name('auth.strava.redirect');
    Route::get('/auth/strava/callback', [StravaAuthController::class, 'callback'])->name('auth.strava.callback');
});

Route::middleware(['auth'])->group(function (): void {
    // Reachable regardless of onboarding status: the wizard itself, and
    // logout (a user stuck mid-wizard must still be able to sign out).
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::post('/logout', [StravaAuthController::class, 'logout'])
        ->name('auth.logout');
});

Route::middleware(['auth', 'onboarded'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Conditional GET on the three history-read pages: the same URL is genuinely
    // revisited (filter/tab toggling, month paging, deep links back into a past
    // run) and their payloads are the largest in the app.
    Route::get('/activities', [RunController::class, 'index'])
        ->middleware('inertia-etag')
        ->name('activities.index');
    Route::get('/activities/{activity}', [RunController::class, 'show'])
        ->middleware('inertia-etag')
        ->name('activities.show');
    Route::post('/activities/{activity}/resync', ResyncActivityController::class)
        ->middleware('throttle:strava-sync')
        ->name('activities.resync');
    Route::post('/activities/{activity}/send', SendActivityNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('activities.send');

    Route::get('/calendar', CalendarController::class)
        ->middleware('inertia-etag')
        ->name('calendar');

    Route::post('/recaps/weekly/{snapshot}/send', SendWeeklyRecapNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('recaps.weekly.send');
    Route::post('/recaps/monthly/{month}/send', SendMonthlyRecapNotificationController::class)
        ->middleware('block-demo-telegram')
        ->name('recaps.monthly.send');

    Route::get('/cards', [CardController::class, 'index'])->name('cards.index');

    // Catatan merged into Activities — keep deep links working.
    Route::permanentRedirect('/catatan', '/activities');

    Route::get('/records', RekorController::class)->name('records');

    Route::get('/race', [RaceController::class, 'index'])->name('race');
    Route::post('/race', [RaceController::class, 'store'])->name('race.store');

    Route::get('/plan', [PlanController::class, 'index'])->name('plan');
    Route::post('/plan/regenerate', [PlanController::class, 'regenerate'])->name('plan.regenerate');
    Route::patch('/plan/sessions/{plannedSession}', [PlanController::class, 'update'])->name('plan.sessions.update');
    Route::delete('/plan/sessions/{plannedSession}', [PlanController::class, 'destroy'])->name('plan.sessions.destroy');
    Route::get('/accessories', [AksesoriController::class, 'index'])->name('accessories');
    Route::post('/api/accessories/equip', [AksesoriController::class, 'equip'])
        ->name('api.accessories.equip');
    Route::get('/badges', [BadgeBoardController::class, 'index'])->name('badges');

    Route::get('/profile', ProfileController::class)->name('profile');

    // The demo is otherwise a fully-interactive shared sandbox (drift resets on
    // demo:seed). Notifications are the one area worth guarding: a visitor could
    // disconnect the shared bot or spam real messages via the send/test endpoints.
    // The block-demo-telegram guard is behaviourally generic (it blocks any demo
    // mutation), so it also covers the channel-neutral preference + test writes.
    Route::patch('/profile/notifications', NotificationPreferenceController::class)->middleware('block-demo-telegram')->name('notifications.preferences.update');
    Route::delete('/profile/telegram', [TelegramConnectionController::class, 'destroy'])->middleware('block-demo-telegram')->name('telegram.disconnect');
    Route::post('/profile/notifications/test', NotificationTestController::class)->middleware(['throttle:6,1', 'block-demo-telegram'])->name('notifications.test');

    // Browser push subscription, managed via fetch from the installed PWA. The
    // block-demo-telegram guard is behaviourally generic (it blocks any demo
    // mutation); the throttle caps abuse of the push send path a subscription feeds.
    Route::post('/profile/push', [PushSubscriptionController::class, 'store'])->middleware(['throttle:6,1', 'block-demo-telegram'])->name('push.subscribe');
    Route::delete('/profile/push', [PushSubscriptionController::class, 'destroy'])->middleware(['throttle:6,1', 'block-demo-telegram'])->name('push.unsubscribe');

    Route::get('/settings', SettingsController::class)->name('settings');

    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::get('/settings/zones', [RunnerZonesController::class, 'index'])->name('settings.zones');
    Route::patch('/settings/zones', [RunnerZonesController::class, 'update'])->name('settings.zones.update');
    Route::delete('/settings/zones', [RunnerZonesController::class, 'resetToDefault'])->name('settings.zones.reset');
    Route::post('/settings/zones/resync-strava', [RunnerZonesController::class, 'resyncFromStrava'])->name('settings.zones.resync');

    Route::post('/strava/sync', SyncController::class)
        ->middleware('throttle:strava-sync')
        ->name('strava.sync');

    // Legacy 301 redirects — keep deep links working from external bookmarks.
    Route::permanentRedirect('/runs', '/activities');
    Route::redirect('/runs/{activity}', '/activities/{activity}', 301);
    Route::permanentRedirect('/progress', '/activities');
    Route::permanentRedirect('/kartu', '/cards');
    Route::permanentRedirect('/pengaturan', '/settings');
    Route::permanentRedirect('/profil', '/profile');
    Route::permanentRedirect('/kalender', '/calendar');
    Route::permanentRedirect('/rekor', '/records');
    Route::permanentRedirect('/aksesori', '/accessories');
    Route::permanentRedirect('/akun', '/account');
    // /goals (the old accessory-progress catalog page) retired in favor of
    // live progress on /accessories — collapse the old /target -> /goals hop
    // to go straight there, and keep /goals itself resolving for bookmarks.
    Route::permanentRedirect('/target', '/accessories');
    Route::permanentRedirect('/goals', '/accessories');

    Route::post('/api/cards/{card}/seen', CardSeenController::class)
        ->name('api.cards.seen');
    Route::post('/api/cards/{card}/replay', CardReplayController::class)
        ->name('api.cards.replay');

    Route::get('/api/analyses/{type}/{subjectId}', [AnalysisController::class, 'show'])
        ->whereNumber('subjectId')
        ->name('api.analyses.show');
    Route::post('/api/analyses/{type}/{subjectId}/trigger', [AnalysisController::class, 'trigger'])
        ->whereNumber('subjectId')
        ->middleware('throttle:analysis-trigger')
        ->name('api.analyses.trigger');

});

// Gated by HTTP Basic Auth against a shared devtools password, independent of
// any Strava session — see EnsureDevtoolsAccess. Throttled like the other
// public POSTs (60/min/IP) so a wrong password can't be brute-forced at
// line speed; generous enough to not trip Pulse's live-polling requests.
Route::middleware(['throttle:60,1', 'devtools'])->group(function (): void {
    Route::get('/devtools', DevtoolsIndexController::class)->name('devtools.index');
    Route::get('/ai-usage', [TokenUsageController::class, 'show'])->name('ai-usage');
    Route::post('/ai-usage/recover', [TokenUsageController::class, 'recover'])->name('ai-usage.recover');
    Route::post('/ai-usage/users/{userId}/retry-failed', [TokenUsageController::class, 'retryFailed'])
        ->whereNumber('userId')
        ->name('ai-usage.retry-failed');
});
