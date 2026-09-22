<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Plan\IntensityPrescriptionResolver;

const PRESCRIPTION_PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];

beforeEach(function (): void {
    $this->resolver = new IntensityPrescriptionResolver();
});

it('starts conservatively then progresses or steps down from comparable evidence', function (): void {
    $cold = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, null, null, PRESCRIPTION_PACES);
    $hit = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, null, null, PRESCRIPTION_PACES, IntentVerdict::Hit, 20);
    $tooHard = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, null, null, PRESCRIPTION_PACES, IntentVerdict::TooHard, 20);

    expect($cold->hardMinutes)->toBe(20)
        ->and($hit->hardMinutes)->toBe(22)
        ->and($tooHard->hardMinutes)->toBe(15);
});

it('uses whole interval repetitions and the phase target', function (): void {
    $prescription = $this->resolver->resolve(SessionType::Interval, PlanPhase::Peak, null, null, PRESCRIPTION_PACES, IntentVerdict::Hit, 12);

    expect($prescription->hardMinutes)->toBe(16)
        ->and($prescription->paceBand)->toBe(PaceBand::Interval);
});

it('uses the slower supported marathon pace and a credible ultra goal pace', function (): void {
    $marathon = $this->resolver->resolve(SessionType::Long, PlanPhase::Peak, 42_195, 12_000, PRESCRIPTION_PACES);
    $slowerMarathon = $this->resolver->resolve(SessionType::Long, PlanPhase::Peak, 42_195, 15_000, PRESCRIPTION_PACES);
    $marathonWithoutVdot = $this->resolver->resolve(SessionType::Long, PlanPhase::Peak, 42_195, 12_000, null);
    $ultra = $this->resolver->resolve(SessionType::Long, PlanPhase::Build, 50_000, 21_000, PRESCRIPTION_PACES);

    expect($marathon->paceSecPerKm)->toBe(300)
        ->and($marathon->hardMinutes)->toBe(15)
        ->and($slowerMarathon->paceSecPerKm)->toBe(355)
        ->and($marathonWithoutVdot->paceSecPerKm)->toBeNull()
        ->and($ultra->hardMinutes)->toBe(0)
        ->and($ultra->isEasy())->toBeTrue()
        ->and($ultra->raceContext['kind'])->toBe('ultra');
});

it('keeps phase and hard-day caps when VDOT is absent, but downgrades when room is absent', function (): void {
    $noPace = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Build, null, null, null);
    $noRoom = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Build, null, null, PRESCRIPTION_PACES, hardMinutesAvailable: 5);

    expect($noPace->hardMinutes)->toBe(20)
        ->and($noPace->paceBand)->toBe(PaceBand::Threshold)
        ->and($noPace->paceSecPerKm)->toBeNull()
        ->and($noPace->isEasy())->toBeFalse()
        ->and($noRoom->isEasy())->toBeTrue();
});

it('does not count a slower-than-easy ultra goal as a hard day', function (): void {
    $prescription = $this->resolver->resolve(SessionType::Long, PlanPhase::Peak, 50_000, 21_000, PRESCRIPTION_PACES);

    expect($prescription->isEasy())->toBeTrue()
        ->and($prescription->hardMinutes)->toBe(0);
});

it('keeps comparable evidence separate for threshold and race-specific work', function (): void {
    expect(IntensityPrescriptionResolver::familyKey(SessionType::Tempo, null, null))->toBe('tempo')
        ->and(IntensityPrescriptionResolver::familyKey(SessionType::Tempo, 42_195, 12_000))->toBe('race_tempo')
        ->and(IntensityPrescriptionResolver::familyKey(SessionType::Long, 50_000, 21_000))->toBe('race_long');
});
