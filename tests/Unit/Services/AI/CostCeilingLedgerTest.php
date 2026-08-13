<?php

declare(strict_types=1);

use App\Services\AI\CostCeilingLedger;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->ledger = app(CostCeilingLedger::class);
});

it('reports an untripped day as nothing to see', function (): void {
    expect($this->ledger->today())->toBe(['trippedAt' => null, 'degradedFills' => 0]);
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
    $this->ledger->recordDegradedFill();
    $this->ledger->recordDegradedFill();
    $this->ledger->recordDegradedFill();

    expect($this->ledger->today()['degradedFills'])->toBe(3);
});

it('starts each day clean rather than carrying yesterday forward', function (): void {
    Carbon::setTestNow('2026-08-14 22:00:00');
    $this->ledger->recordTrip();
    $this->ledger->recordDegradedFill();

    Carbon::setTestNow('2026-08-15 00:30:00');

    expect($this->ledger->today())->toBe(['trippedAt' => null, 'degradedFills' => 0]);
});
