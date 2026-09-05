<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Services\Run\Plan\SessionSegment;

it('serializes every field, including a null minutes/pace when no VDOT estimate exists yet', function (): void {
    $segment = new SessionSegment(SegmentKey::Main, null, 'Z2', PaceBand::Easy, null);

    expect($segment->toArray())->toBe([
        'key' => 'main',
        'minutes' => null,
        'zone' => 'Z2',
        'pace_label' => 'easy',
        'pace_sec_per_km' => null,
    ]);
});

it('serializes a fully computed segment', function (): void {
    $segment = new SessionSegment(SegmentKey::Interval, 3.0, 'Z5', PaceBand::Interval, 240);

    expect($segment->toArray())->toBe([
        'key' => 'interval',
        'minutes' => 3.0,
        'zone' => 'Z5',
        'pace_label' => 'interval',
        'pace_sec_per_km' => 240,
    ]);
});
