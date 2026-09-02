<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The asset version the middleware will actually compare against. Inertia's
 * `Middleware::handle()` only (re)binds the `Inertia::version()` closure at
 * the start of a request it processes — calling the `Inertia::getVersion()`
 * facade outside that cycle reads whatever the *previous* request left
 * behind (or nothing, before any request has run), so it silently drifts
 * from the real value the moment a local `npm run build` populates
 * `public/build/manifest.json` (absent in CI, present on a dev machine that's
 * run a build). Resolving it the same way the middleware does keeps this
 * deterministic in both environments.
 */
function currentInertiaVersion(): string
{
    return (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
}

/**
 * A full Inertia page visit (no partial headers), optionally revalidating.
 */
function etagVisit(string $url, ?string $ifNoneMatch = null): TestResponse
{
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => currentInertiaVersion()];

    if ($ifNoneMatch !== null) {
        $headers['If-None-Match'] = $ifNoneMatch;
    }

    return test()->get($url, $headers);
}

function etagSeedRun(User $user): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);

    return $activity;
}

it('tags a full Inertia page visit with an ETag and a revalidate-always private policy', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);

    $this->actingAs($user);
    $response = etagVisit("/activities/{$activity->id}")->assertSuccessful();

    expect($response->headers->get('ETag'))->not->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
});

it('answers a replayed ETag with an empty 304 on every tagged route', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    foreach (['/history', '/history?view=calendar', "/activities/{$activity->id}"] as $url) {
        $etag = etagVisit($url)->assertSuccessful()->headers->get('ETag');

        $revalidated = etagVisit($url, $etag);

        expect($revalidated->getStatusCode())->toBe(304)
            ->and($revalidated->getContent())->toBe('');
    }
});

it('never answers one user with another user\'s 304 on a shared URL', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    etagSeedRun($alice);
    etagSeedRun($bob);

    $this->actingAs($alice);
    $aliceEtag = etagVisit('/history?view=calendar')->assertSuccessful()->headers->get('ETag');

    $this->actingAs($bob);
    $bobResponse = etagVisit('/history?view=calendar', $aliceEtag)->assertSuccessful();

    expect($bobResponse->getStatusCode())->toBe(200)
        ->and($bobResponse->headers->get('ETag'))->not->toBe($aliceEtag)
        ->and($bobResponse->getContent())->toContain('"id":'.$bob->id);
});

it('misses when the page data moves', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    $etag = etagVisit('/history')->assertSuccessful()->headers->get('ETag');

    $activity->detail->update(['name' => 'A different evening run']);

    expect(etagVisit('/history', $etag)->assertSuccessful()->getStatusCode())->toBe(200);
});

it('misses when a shared prop moves even though the page data did not', function (): void {
    $user = User::factory()->create();
    etagSeedRun($user);
    $this->actingAs($user);

    $etag = etagVisit('/history?view=calendar')->assertSuccessful()->headers->get('ETag');

    $user->forceFill(['name' => 'Nama Baru'])->save();

    expect(etagVisit('/history?view=calendar', $etag)->assertSuccessful()->getStatusCode())->toBe(200);
});

it('never serves a stale flash from a 304', function (): void {
    $user = User::factory()->create();
    etagSeedRun($user);
    $this->actingAs($user);

    $flashed = $this->withSession(['success' => 'Sinkron jalan.'])
        ->get('/history?view=calendar', ['X-Inertia' => 'true', 'X-Inertia-Version' => currentInertiaVersion()])
        ->assertSuccessful();

    expect($flashed->getContent())->toContain('Sinkron jalan.');

    $this->flushSession();

    $quiet = etagVisit('/history?view=calendar', $flashed->headers->get('ETag'))->assertSuccessful();

    expect($quiet->getStatusCode())->toBe(200)
        ->and($quiet->getContent())->not->toContain('Sinkron jalan.');
});

it('keeps a partial reload out of every cache and leaves it untagged', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);

    $response = $this->actingAs($user)->get("/activities/{$activity->id}", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => currentInertiaVersion(),
        'X-Inertia-Partial-Component' => 'Runs/Show',
        'X-Inertia-Partial-Data' => 'speechAnalysis',
    ])->assertSuccessful();

    expect($response->headers->get('ETag'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('leaves the initial HTML document untagged', function (): void {
    $user = User::factory()->create();
    etagSeedRun($user);

    $response = $this->actingAs($user)->get('/history?view=calendar')->assertSuccessful();

    expect($response->headers->get('ETag'))->toBeNull();
});

it('leaves untagged routes alone', function (): void {
    $user = User::factory()->create();
    etagSeedRun($user);

    $this->actingAs($user);

    expect(etagVisit('/')->assertSuccessful()->headers->get('ETag'))->toBeNull();
});

it('does not tag a non-200 Inertia response', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();

    $this->actingAs($user);

    $response = etagVisit("/activities/{$activity->id}");

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('ETag'))->toBeNull();
});
