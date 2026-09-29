<?php

declare(strict_types=1);

use App\Services\AI\CostCeilingLedger;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->ledger = app(CostCeilingLedger::class);
});

it('reports an untripped day as nothing to see', function (): void {
    expect($this->ledger->today())->toBe(['trippedAt' => null, 'degradedFills' => 0, 'degradedBreakdown' => []]);
});

it('keeps the first trip time, not the latest', function (): void {
    Carbon::setTestNow('2026-08-14 09:05:00');
    $this->ledger->recordTrip();

    Carbon::setTestNow('2026-08-14 17:40:00');
    $this->ledger->recordTrip();

    expect($this->ledger->today()['trippedAt'])->toStartWith('2026-08-14T09:05:00');
});

it('counts every degraded fill of the day', function (): void {
    Carbon::setTestNow('2026-08-14 09:05:00');
    $this->ledger->recordDegradedFill('run_insight', 1);
    $this->ledger->recordDegradedFill('run_insight', 1);
    $this->ledger->recordDegradedFill('run_insight', 1);

    expect($this->ledger->today()['degradedFills'])->toBe(3);
});

it('starts each day clean rather than carrying yesterday forward', function (): void {
    Carbon::setTestNow('2026-08-14 22:00:00');
    $this->ledger->recordTrip();
    $this->ledger->recordDegradedFill('run_insight', 1);

    Carbon::setTestNow('2026-08-15 00:30:00');

    expect($this->ledger->today())->toBe(['trippedAt' => null, 'degradedFills' => 0, 'degradedBreakdown' => []]);
});

it('breaks the day down by kind and athlete', function (): void {
    Carbon::setTestNow('2026-08-14 09:05:00');
    $this->ledger->recordDegradedFill('run_insight', 1);
    $this->ledger->recordDegradedFill('run_insight', 1);
    $this->ledger->recordDegradedFill('run_insight', 2);
    $this->ledger->recordDegradedFill('run_question', 1);

    expect($this->ledger->today()['degradedBreakdown'])->toBe([
        ['kind' => 'run_insight', 'userId' => 1, 'count' => 2],
        ['kind' => 'run_insight', 'userId' => 2, 'count' => 1],
        ['kind' => 'run_question', 'userId' => 1, 'count' => 1],
    ]);
});
