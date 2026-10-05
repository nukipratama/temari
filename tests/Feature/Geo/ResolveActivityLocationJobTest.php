<?php

declare(strict_types=1);

use App\Actions\Geo\ReverseGeocodeAction;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\ActivityDetail;
use App\Services\Geo\ResolvedLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('writes resolved location + stamps resolved_at on success', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => null,
        'location_resolved_at' => null,
    ]);

    $this->mock(ReverseGeocodeAction::class, function ($m): void {
        $m->shouldReceive('__invoke')
            ->once()
            ->andReturn(new ResolvedLocation('Jakarta Selatan, DKI Jakarta, Indonesia', 'ID'));
    });

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

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

    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldReceive('__invoke')->once()->andReturn(null));

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

    $detail->refresh();
    expect($detail->location_name)->toBeNull();
    // A null Nominatim result leaves the row unresolved for the geo:backfill sweep.
    expect($detail->location_resolved_at)->toBeNull();
    expect($detail->location_attempts)->toBe(1)
        ->and($detail->location_attempted_at)->not->toBeNull();
});

it('does not resolve a row that reached five attempts', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => 0.0,
        'start_lng' => 0.0,
        'location_resolved_at' => null,
        'location_attempts' => 5,
    ]);

    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldReceive('__invoke')->never());

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

    expect($detail->fresh()->location_attempts)->toBe(5);
});

it('waits a day after a failed attempt before resolving again', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => 0.0,
        'start_lng' => 0.0,
        'location_resolved_at' => null,
        'location_attempts' => 1,
        'location_attempted_at' => now()->subHours(23),
    ]);

    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldReceive('__invoke')->never());

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

    expect($detail->fresh()->location_attempts)->toBe(1);
});

it('skips already-resolved details', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'cached',
        'location_resolved_at' => now()->subDay(),
    ]);

    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldNotReceive('__invoke'));

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

    expect($detail->fresh()->location_name)->toBe('cached');
});

it('stamps and exits when the detail has no coords', function (): void {
    $detail = ActivityDetail::factory()->create([
        'start_lat' => null,
        'start_lng' => null,
        'location_resolved_at' => null,
    ]);

    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldNotReceive('__invoke'));

    new ResolveActivityLocationJob($detail->id)->handle(app(ReverseGeocodeAction::class));

    $detail->refresh();
    expect($detail->location_resolved_at)->not->toBeNull();
    expect($detail->location_name)->toBeNull();
});

it('is a no-op when the detail row was deleted before the job ran', function (): void {
    $this->mock(ReverseGeocodeAction::class, fn ($m) => $m->shouldNotReceive('__invoke'));

    new ResolveActivityLocationJob(999_999)->handle(app(ReverseGeocodeAction::class));

    expect(true)->toBeTrue();
});

it('releases when the shared Nominatim request slot is unavailable', function (): void {
    Cache::flush();
    $detail = ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);
    Cache::lock('geo:nominatim:request-slot-lock', 20)->get();
    Http::fake();

    $job = new ResolveActivityLocationJob($detail->id)->withFakeQueueInteractions();
    $job->handle(new ReverseGeocodeAction());

    $job->assertReleased(2);
    Http::assertNothingSent();
});
