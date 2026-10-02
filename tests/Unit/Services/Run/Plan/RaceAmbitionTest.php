<?php

declare(strict_types=1);

use App\Enums\RaceAmbitionState;
use App\Services\Run\Plan\RaceAmbition;

it('prescribes the target unless it is unsupported, then the supported time', function (): void {
    $onTrack = new RaceAmbition(RaceAmbitionState::OnTrack, 3000, 300, 3050, 305, 1.6, 'confirmed');
    $unsupported = new RaceAmbition(RaceAmbitionState::Unsupported, 3000, 300, 4200, 420, 28.6, 'confirmed');
    $unknown = new RaceAmbition(RaceAmbitionState::Unknown, 3000, 300, null, null, null, null);

    expect($onTrack->prescribedTimeSec())->toBe(3000)
        ->and($unsupported->prescribedTimeSec())->toBe(4200)
        ->and($unknown->prescribedTimeSec())->toBe(3000);
});

it('exposes both numbers under stable payload keys', function (): void {
    $ambition = new RaceAmbition(RaceAmbitionState::Unsupported, 3000, 300, 4200, 420, 28.6, 'confirmed');

    expect($ambition->toArray())->toBe([
        'state' => 'unsupported',
        'target_time_sec' => 3000,
        'target_pace_sec_per_km' => 300,
        'supported_time_sec' => 4200,
        'supported_pace_sec_per_km' => 420,
        'prescribed_time_sec' => 4200,
        'gap_pct' => 28.6,
        'evidence_confidence' => 'confirmed',
    ]);
});
