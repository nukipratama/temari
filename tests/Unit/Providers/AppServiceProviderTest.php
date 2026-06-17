<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AI\AnalysisService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Strava\Provider as StravaProvider;

it('registers the Strava socialite provider via the SocialiteWasCalled listener', function (): void {
    config([
        'services.strava.client_id' => 'test-id',
        'services.strava.client_secret' => 'test-secret',
        'services.strava.redirect' => 'http://localhost/auth/strava/callback',
    ]);

    expect(Socialite::driver('strava'))->toBeInstanceOf(StravaProvider::class);
});

it('analysis-trigger limiter keys by user id when authenticated', function (): void {
    config()->set('ai.rate_limit_per_minute', 5);
    $user = User::factory()->make(['id' => 99]);

    $request = Request::create('/api/analyses/foo/1/trigger', 'POST');
    $request->setUserResolver(fn () => $user);

    $limit = RateLimiter::limiter('analysis-trigger')($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('99')
        ->and($limit->maxAttempts)->toBe(5);
});

it('analysis-trigger limiter falls back to IP when unauthenticated', function (): void {
    config()->set('ai.rate_limit_per_minute', 3);

    $request = Request::create('/api/analyses/foo/1/trigger', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $limit = RateLimiter::limiter('analysis-trigger')($request);

    expect($limit->key)->toBe('203.0.113.7')
        ->and($limit->maxAttempts)->toBe(3);
});

it('shares one AnalysisService instance within a single request/CLI scope', function (): void {
    expect(app(AnalysisService::class))->toBe(app(AnalysisService::class));
});

it('isOpsUser allows any user in the local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    expect(AppServiceProvider::isOpsUser(null))->toBeTrue()
        ->and(AppServiceProvider::isOpsUser(User::factory()->make()))->toBeTrue();
});

it('isOpsUser leaves the gate open when no ops allow-list is configured', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.ops_emails', []);

    expect(AppServiceProvider::isOpsUser(null))->toBeTrue()
        ->and(AppServiceProvider::isOpsUser(User::factory()->make()))->toBeTrue()
        ->and(AppServiceProvider::isOpsUser(User::factory()->demo()->make()))->toBeTrue();
});

it('isOpsUser excludes guests and demo once an ops allow-list is configured', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.ops_emails', ['ops@teman-lari.local']);

    expect(AppServiceProvider::isOpsUser(null))->toBeFalse()
        ->and(AppServiceProvider::isOpsUser(User::factory()->demo()->make(['email' => 'ops@teman-lari.local'])))->toBeFalse();
});

it('isOpsUser allows a configured ops email and denies others outside local', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.ops_emails', ['ops@teman-lari.local']);

    expect(AppServiceProvider::isOpsUser(User::factory()->make(['email' => 'ops@teman-lari.local'])))->toBeTrue()
        ->and(AppServiceProvider::isOpsUser(User::factory()->make(['email' => 'random@example.com'])))->toBeFalse();
});

it('viewPulse and viewAiUsage gates honor the ops allow-list outside local', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('app.ops_emails', ['ops@teman-lari.local']);

    $ops = User::factory()->make(['email' => 'ops@teman-lari.local']);
    $stranger = User::factory()->make(['email' => 'random@example.com']);

    expect(Gate::forUser($ops)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($ops)->allows('viewAiUsage'))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAiUsage'))->toBeFalse();
});

it('binds AnalysisService as scoped, not a cross-request singleton', function (): void {
    // Under Octane the worker stays booted; `scoped` flushes between requests so
    // a leaked `withoutDispatching()` flag can't survive into the next request.
    $first = app(AnalysisService::class);

    // Octane fires this between requests (Laravel\Octane\Listeners\FlushOnceQueuedThings).
    app()->forgetScopedInstances();

    expect(app(AnalysisService::class))->not->toBe($first);
});
