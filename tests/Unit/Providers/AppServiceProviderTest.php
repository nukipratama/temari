<?php

declare(strict_types=1);

use App\Models\User;
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

it('leaves the viewPulse and viewAiUsage gates open (edge basicauth is the sole gate)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $user = User::factory()->make(['email' => 'random@example.com']);
    $demo = User::factory()->demo()->make();

    expect(Gate::forUser($user)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewAiUsage'))->toBeTrue()
        ->and(Gate::forUser($demo)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($demo)->allows('viewAiUsage'))->toBeTrue();
});

it('binds AnalysisService as scoped, not a cross-request singleton', function (): void {
    // Under Octane the worker stays booted; `scoped` flushes between requests so
    // a leaked `withoutDispatching()` flag can't survive into the next request.
    $first = app(AnalysisService::class);

    // Octane fires this between requests (Laravel\Octane\Listeners\FlushOnceQueuedThings).
    app()->forgetScopedInstances();

    expect(app(AnalysisService::class))->not->toBe($first);
});
