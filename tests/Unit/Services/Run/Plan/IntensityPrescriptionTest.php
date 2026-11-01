<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Models\PlannedSession;
use App\Services\Run\Plan\IntensityPrescription;
use App\Enums\SessionType;
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
    expect(new IntensityPrescription(0, null, null, null)->isEasy())->toBeTrue();
});

it('runs a quality day prescribed with no hard work as an easy day', function (string $type, ?int $hardMinutes, SessionType $runsAs): void {
    $session = PlannedSession::factory()->make([
        'session_type' => $type,
        'prescribed_hard_minutes' => $hardMinutes,
        'prescribed_pace_band' => $hardMinutes ? PaceBand::Threshold : null,
    ]);

    expect(IntensityPrescription::runsAs($session))->toBe($runsAs);
})->with([
    'easy tempo' => ['tempo', 0, SessionType::Easy],
    'easy interval' => ['interval', 0, SessionType::Easy],
    'hard tempo' => ['tempo', 20, SessionType::Tempo],
    'unprescribed interval' => ['interval', null, SessionType::Interval],
    'long run' => ['long', 0, SessionType::Long],
]);
