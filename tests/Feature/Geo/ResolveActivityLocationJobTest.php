<?php

declare(strict_types=1);

use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Geo\NominatimResolver;
use App\Services\Geo\ResolvedLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes resolved location + stamps resolved_at on success', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => null,
        'location_resolved_at' => null,
    ]);

    $this->mock(NominatimResolver::class, function ($m): void {
        $m->shouldReceive('reverse')
            ->once()
            ->andReturn(new ResolvedLocation('Jakarta Selatan, DKI Jakarta, Indonesia', 'ID'));
    });

    (new ResolveActivityLocationJob($detail->id))->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_name)->toBe('Jakarta Selatan, DKI Jakarta, Indonesia');
    expect($detail->location_country)->toBe('ID');
    expect($detail->location_resolved_at)->not->toBeNull();
});

it('stamps resolved_at with null name when resolver returns null (so we don\'t retry)', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_lat' => 0.0,
        'start_lng' => 0.0,
        'location_resolved_at' => null,
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldReceive('reverse')->once()->andReturn(null));

    (new ResolveActivityLocationJob($detail->id))->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_name)->toBeNull();
    expect($detail->location_resolved_at)->not->toBeNull();
});

it('skips already-resolved details', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'cached',
        'location_resolved_at' => now()->subDay(),
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    (new ResolveActivityLocationJob($detail->id))->handle(app(NominatimResolver::class));

    expect($detail->fresh()->location_name)->toBe('cached');
});

it('stamps and exits when the detail has no coords', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_lat' => null,
        'start_lng' => null,
        'location_resolved_at' => null,
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    (new ResolveActivityLocationJob($detail->id))->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_resolved_at)->not->toBeNull();
    expect($detail->location_name)->toBeNull();
});

it('is a no-op when the detail row was deleted before the job ran', function (): void {
    // Mockery's `shouldNotReceive` registers a tear-down assertion, so we
    // can't pair it with `throwsNoExceptions()` — PHPUnit's strict mode
    // would flag the run as risky. The expectation is enforced via the
    // mock, the missing-row branch returns silently.
    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    (new ResolveActivityLocationJob(999_999))->handle(app(NominatimResolver::class));

    expect(true)->toBeTrue();
});

it('declares a WithoutOverlapping middleware on the geo:nominatim:reverse key', function (): void {
    $job = new ResolveActivityLocationJob(1);
    $middleware = $job->middleware();
    expect($middleware)->not->toBeEmpty();
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
