<?php

declare(strict_types=1);

use App\Services\AI\RecapPeriod;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns the previous week ending (Sunday) as the last closed week', function (): void {
    Carbon::setTestNow('2026-06-17'); // Wednesday; current week ends 2026-06-21

    expect(RecapPeriod::lastClosedWeekEnding())->toBe('2026-06-14');
});

it('returns the previous month (Y-m) as the last closed month', function (): void {
    Carbon::setTestNow('2026-06-17');

    expect(RecapPeriod::lastClosedMonth())->toBe('2026-05');
});

it('does not overflow when last month has fewer days', function (): void {
    Carbon::setTestNow('2026-03-31');

    expect(RecapPeriod::lastClosedMonth())->toBe('2026-02');
});

it('classifies a week as pre-connect only when it ended before the connection landed', function (): void {
    $connectedAt = Carbon::parse('2026-06-15 08:00:00');

    expect(RecapPeriod::weekClosedBeforeConnect('2026-06-14', $connectedAt))->toBeTrue()
        ->and(RecapPeriod::weekClosedBeforeConnect(Carbon::parse('2026-06-14'), $connectedAt))->toBeTrue()
        ->and(RecapPeriod::weekClosedBeforeConnect('2026-06-21', $connectedAt))->toBeFalse()
        ->and(RecapPeriod::weekClosedBeforeConnect('2026-06-14', Carbon::parse('2026-06-14 20:00:00')))->toBeFalse()
        ->and(RecapPeriod::weekClosedBeforeConnect('2026-06-14', null))->toBeFalse();
});

it('leaves the week ending it was handed untouched', function (): void {
    $weekEnding = Carbon::parse('2026-06-14');

    RecapPeriod::weekClosedBeforeConnect($weekEnding, Carbon::parse('2026-06-15'));

    expect($weekEnding->toDateTimeString())->toBe('2026-06-14 00:00:00');
});

it('classifies a month as pre-connect only when it ended before the connection landed', function (): void {
    $connectedAt = Carbon::parse('2026-06-02 08:00:00');

    expect(RecapPeriod::monthClosedBeforeConnect('2026-05', $connectedAt))->toBeTrue()
        ->and(RecapPeriod::monthClosedBeforeConnect('2026-06', $connectedAt))->toBeFalse()
        ->and(RecapPeriod::monthClosedBeforeConnect('2026-05', Carbon::parse('2026-05-31 20:00:00')))->toBeFalse()
        ->and(RecapPeriod::monthClosedBeforeConnect('2026-05', null))->toBeFalse();
});
