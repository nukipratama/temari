<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\AI\Context\ActivityNarrationContext;
use Illuminate\Support\Carbon;

function narrationDetail(array $attributes = []): ActivityDetail
{
    return ActivityDetail::factory()->make([
        'activity_id' => 1,
        'start_date_local' => Carbon::today(),
        ...$attributes,
    ]);
}

it('builds the shared fields from a populated detail', function (): void {
    $detail = narrationDetail([
        'distance' => 8250.0,
        'weather_temp_c' => 31,
        'weather_rain_detected' => true,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z1' => 10, 'Z2' => 70, 'Z3' => 20],
            'decoupling_pct' => 5.2,
            'negative_split' => true,
        ],
    ]);

    $ctx = ActivityNarrationContext::fromDetail($detail);

    expect($ctx->distanceMeters)->toBe(8250.0)
        ->and($ctx->decouplingPct)->toBe(5.2)
        ->and($ctx->negativeSplit)->toBeTrue()
        ->and($ctx->zonePct)->toBe(['Z1' => 10, 'Z2' => 70, 'Z3' => 20])
        ->and($ctx->weatherTempC)->toBe(31)
        ->and($ctx->weatherRain)->toBeTrue();
});

it('rounds distance to the requested precision', function (): void {
    $detail = narrationDetail(['distance' => 8256.0]);

    $ctx = ActivityNarrationContext::fromDetail($detail);

    expect($ctx->distanceKm(1))->toBe(8.3)
        ->and($ctx->distanceKm(2))->toBe(8.26)
        ->and($ctx->distanceKmOrNull(2))->toBe(8.26);
});

it('treats a missing distance as 0 km but null for the nullable accessor', function (): void {
    $detail = narrationDetail(['distance' => null]);

    $ctx = ActivityNarrationContext::fromDetail($detail);

    expect($ctx->distanceMeters)->toBeNull()
        ->and($ctx->distanceKm(1))->toBe(0.0)
        ->and($ctx->distanceKm(2))->toBe(0.0)
        ->and($ctx->distanceKmOrNull(2))->toBeNull();
});

it('falls back to nulls and an empty zone map when the stream summary is null', function (): void {
    $detail = narrationDetail(['stream_summary' => null]);

    $ctx = ActivityNarrationContext::fromDetail($detail);

    expect($ctx->decouplingPct)->toBeNull()
        ->and($ctx->negativeSplit)->toBeNull()
        ->and($ctx->zonePct)->toBe([]);
});

it('builds an all-null context when the detail itself is missing', function (): void {
    $ctx = ActivityNarrationContext::fromDetail(null);

    expect($ctx->distanceMeters)->toBeNull()
        ->and($ctx->decouplingPct)->toBeNull()
        ->and($ctx->negativeSplit)->toBeNull()
        ->and($ctx->zonePct)->toBe([])
        ->and($ctx->weatherTempC)->toBeNull()
        ->and($ctx->weatherRain)->toBeNull()
        ->and($ctx->distanceKm(1))->toBe(0.0)
        ->and($ctx->distanceKmOrNull(2))->toBeNull();
});
