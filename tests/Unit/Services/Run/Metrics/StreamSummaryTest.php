<?php

declare(strict_types=1);

use App\Services\Run\Metrics\StreamSummary;

it('reads the time-in-zone percent breakdown', function (): void {
    $summary = ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 60, 'Z3' => 30]];

    expect(StreamSummary::zonePct($summary))->toBe(['Z1' => 10, 'Z2' => 60, 'Z3' => 30]);
});

it('returns an empty zone breakdown when time_in_zone_pct is missing or malformed', function (array $summary): void {
    expect(StreamSummary::zonePct($summary))->toBe([]);
})->with([
    'missing key' => [[]],
    'not an array' => [['time_in_zone_pct' => 'nope']],
]);

it('sums Z3+Z4+Z5 as the hard-zone share', function (): void {
    $summary = ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 20, 'Z3' => 30, 'Z4' => 25, 'Z5' => 15]];

    expect(StreamSummary::hardZoneShare($summary))->toBe(70.0);
});

it('treats missing zone keys as zero when summing the hard-zone share', function (): void {
    $summary = ['time_in_zone_pct' => ['Z1' => 40, 'Z2' => 60]];

    expect(StreamSummary::hardZoneShare($summary))->toBe(0.0);
});

it('returns a zero hard-zone share when time_in_zone_pct is missing or malformed', function (array $summary): void {
    expect(StreamSummary::hardZoneShare($summary))->toBe(0.0);
})->with([
    'missing key' => [[]],
    'not an array' => [['time_in_zone_pct' => 'nope']],
]);
