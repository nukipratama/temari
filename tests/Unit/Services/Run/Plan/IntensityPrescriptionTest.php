<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Models\PlannedSession;
use App\Services\Run\Plan\IntensityPrescription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serializes the persisted prescription contract and restores it from a row', function (): void {
    $prescription = new IntensityPrescription(25, PaceBand::Threshold, 270, 'progressed after a hit');
    $row = PlannedSession::factory()->make($prescription->toArray());

    expect($prescription->isEasy())->toBeFalse()
        ->and($prescription->toArray()['prescribed_hard_minutes'])->toBe(25)
        ->and(IntensityPrescription::fromSession($row))->toEqual($prescription)
        ->and(IntensityPrescription::fromSession(PlannedSession::factory()->make()))->toBeNull();
});

it('recognises a zero-minute prescription as easy', function (): void {
    expect(new IntensityPrescription(0, null, null, 'easy volume')->isEasy())->toBeTrue();
});
