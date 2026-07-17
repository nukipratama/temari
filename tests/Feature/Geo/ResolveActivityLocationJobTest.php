<?php

declare(strict_types=1);

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\ActivityDetail;
use App\Services\Geo\NominatimResolver;
use App\Services\Geo\ResolvedLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;

uses(RefreshDatabase::class);

it('writes resolved location + stamps resolved_at on success', function (): void {
    $detail = ActivityDetail::factory()->create([
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

    new ResolveActivityLocationJob($detail->id)->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_name)->toBe('Jakarta Selatan, DKI Jakarta, Indonesia');
    expect($detail->location_country)->toBe('ID');
    expect($detail->location_resolved_at)->not->toBeNull();
});

it('leaves resolved_at null on a transient miss so the catch-up retries', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => 0.0,
        'start_lng' => 0.0,
        'location_resolved_at' => null,
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldReceive('reverse')->once()->andReturn(null));

    new ResolveActivityLocationJob($detail->id)->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_name)->toBeNull();
    // A null Nominatim result leaves the row unresolved for the geo:backfill sweep.
    expect($detail->location_resolved_at)->toBeNull();
});

it('skips already-resolved details', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'cached',
        'location_resolved_at' => now()->subDay(),
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    new ResolveActivityLocationJob($detail->id)->handle(app(NominatimResolver::class));

    expect($detail->fresh()->location_name)->toBe('cached');
});

it('stamps and exits when the detail has no coords', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => null,
        'start_lng' => null,
        'location_resolved_at' => null,
    ]);

    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    new ResolveActivityLocationJob($detail->id)->handle(app(NominatimResolver::class));

    $detail->refresh();
    expect($detail->location_resolved_at)->not->toBeNull();
    expect($detail->location_name)->toBeNull();
});

it('is a no-op when the detail row was deleted before the job ran', function (): void {
    $this->mock(NominatimResolver::class, fn ($m) => $m->shouldNotReceive('reverse'));

    new ResolveActivityLocationJob(999_999)->handle(app(NominatimResolver::class));

    expect(true)->toBeTrue();
});

it('declares a WithoutOverlapping middleware on the geo:nominatim:reverse key', function (): void {
    $job = new ResolveActivityLocationJob(1);
    $middleware = $job->middleware();
    expect($middleware)->not->toBeEmpty();
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
