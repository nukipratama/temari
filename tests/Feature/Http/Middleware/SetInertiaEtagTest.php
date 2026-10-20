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
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/**
 * The asset version the middleware will actually compare against. Inertia's
 * `Middleware::handle()` only binds the `Inertia::version()` closure at the
 * start of a request it processes, so calling `Inertia::getVersion()` outside
 * that cycle drifts from the real value once a local `npm run build` populates
 * `public/build/manifest.json`. Resolving it the way the middleware does keeps
 * this deterministic in CI and on a dev machine.
 */
function currentInertiaVersion(): string
{
    return (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
}

/**
 * A full Inertia page visit (no partial headers), optionally revalidating.
 *
 * @return TestResponse<Response>
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

it('answers a replayed ETag with an empty 304', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    $url = "/activities/{$activity->id}";
    $etag = etagVisit($url)->assertSuccessful()->headers->get('ETag');

    $revalidated = etagVisit($url, $etag);

    expect($revalidated->getStatusCode())->toBe(304)
        ->and($revalidated->getContent())->toBe('');
});

it('never lets a replayed ETag short-circuit authorization', function (): void {
    // Every tagged route is owner-scoped; a 304 must never be the reason a
    // request skips its own authorization.
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceRun = etagSeedRun($alice);
    $bobRun = etagSeedRun($bob);

    $this->actingAs($alice);
    $aliceEtag = etagVisit("/activities/{$aliceRun->id}")->assertSuccessful()->headers->get('ETag');

    $this->actingAs($bob);

    expect(etagVisit("/activities/{$aliceRun->id}", $aliceEtag)->getStatusCode())->toBe(404);

    $bobResponse = etagVisit("/activities/{$bobRun->id}", $aliceEtag)->assertSuccessful();

    expect($bobResponse->getStatusCode())->toBe(200)
        ->and($bobResponse->headers->get('ETag'))->not->toBe($aliceEtag)
        ->and($bobResponse->getContent())->toContain('"id":'.$bob->id);
});

it('misses when the page data moves', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    $etag = etagVisit("/activities/{$activity->id}")->assertSuccessful()->headers->get('ETag');

    nonNull($activity->detail)->update(['name' => 'A different evening run']);

    expect(etagVisit("/activities/{$activity->id}", $etag)->assertSuccessful()->getStatusCode())->toBe(200);
});

it('leaves /history untagged now that its payload is deferred', function (): void {
    // The alias was bought for /history's 10 KB page object. Its run list,
    // calendar grid and recaps now arrive in a deliberately no-store partial,
    // so a tag there would only ever cover a 1.3 KB shell.
    $user = User::factory()->create();
    etagSeedRun($user);
    $this->actingAs($user);

    expect(etagVisit('/history')->assertSuccessful()->headers->get('ETag'))->toBeNull()
        ->and(etagVisit('/history?view=calendar')->assertSuccessful()->headers->get('ETag'))->toBeNull();
});

it('misses when a shared prop moves even though the page data did not', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    $etag = etagVisit("/activities/{$activity->id}")->assertSuccessful()->headers->get('ETag');

    $user->forceFill(['name' => 'New Name'])->save();

    expect(etagVisit("/activities/{$activity->id}", $etag)->assertSuccessful()->getStatusCode())->toBe(200);
});

it('never serves a stale flash from a 304', function (): void {
    $user = User::factory()->create();
    $activity = etagSeedRun($user);
    $this->actingAs($user);

    $flashed = $this->withSession(['success' => 'Run synced.'])
        ->get("/activities/{$activity->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => currentInertiaVersion()])
        ->assertSuccessful();

    expect($flashed->getContent())->toContain('Run synced.');

    $this->flushSession();

    $quiet = etagVisit("/activities/{$activity->id}", $flashed->headers->get('ETag'))->assertSuccessful();

    expect($quiet->getStatusCode())->toBe(200)
        ->and($quiet->getContent())->not->toContain('Run synced.');
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
    $activity = etagSeedRun($user);

    $response = $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

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
