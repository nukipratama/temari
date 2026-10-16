<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Services\Run\Plan\PaceRange;
use App\Services\Run\Plan\SessionSegment;

function paceRangeSegment(PaceBand $label, ?int $pace, SegmentKey $key = SegmentKey::Main): SessionSegment
{
    return new SessionSegment($key, 10.0, 'Z3', $label, $pace);
}

it('runs a hard step from 5% quicker to the grading tolerance slower', function (PaceBand $label): void {
    expect(PaceRange::forSegment(paceRangeSegment($label, 360), false, 393, 452)?->toArray())
        ->toBe(['fast_sec_per_km' => 342, 'slow_sec_per_km' => 370]);
})->with([PaceBand::Threshold, PaceBand::Interval]);

it('keeps a marathon-pace block within the grading tolerance either side', function (): void {
    expect(PaceRange::forSegment(paceRangeSegment(PaceBand::Marathon, 393), false, 393, 452)?->toArray())
        ->toBe(['fast_sec_per_km' => 383, 'slow_sec_per_km' => 403]);
});

it('runs a marathon race on the hard window, since it is a race effort', function (): void {
    expect(PaceRange::forSegment(paceRangeSegment(PaceBand::Marathon, 393), true, 393, 452)?->toArray())
        ->toBe(['fast_sec_per_km' => 373, 'slow_sec_per_km' => 403]);
});

it('lets easy running span marathon pace to the slow end of easy', function (): void {
    expect(PaceRange::forSegment(paceRangeSegment(PaceBand::Easy, 432, SegmentKey::Warmup), false, 393, 452)?->toArray())
        ->toBe(['fast_sec_per_km' => 393, 'slow_sec_per_km' => 452]);
});

it('widens the easy window to hold an eased target outside it', function (): void {
    expect(PaceRange::forSegment(paceRangeSegment(PaceBand::Easy, 470), false, 393, 452)?->toArray())
        ->toBe(['fast_sec_per_km' => 393, 'slow_sec_per_km' => 470]);
});

it('has no window without a target pace, or for easy running without the athlete paces', function (): void {
    expect(PaceRange::forSegment(paceRangeSegment(PaceBand::Threshold, null), false, 393, 452))->toBeNull()
        ->and(PaceRange::forSegment(paceRangeSegment(PaceBand::Easy, 432), false, null, 452))->toBeNull()
        ->and(PaceRange::forSegment(paceRangeSegment(PaceBand::Easy, 432), false, 393, null))->toBeNull();
});
