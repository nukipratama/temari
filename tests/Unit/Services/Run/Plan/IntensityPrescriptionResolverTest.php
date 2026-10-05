<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\SessionType;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\GoalPaceWork;
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
        ->and($tooHard->isEasy())->toBeTrue();
});

it('steps tempo down by one phase-shaped work block', function (): void {
    $base = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Base, null, null, PRESCRIPTION_PACES, IntentVerdict::TooHard, 20);
    $build = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Build, null, null, PRESCRIPTION_PACES, IntentVerdict::TooHard, 20);
    $taper = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Taper, null, null, PRESCRIPTION_PACES, IntentVerdict::TooHard, 20);

    expect($base->hardMinutes)->toBe(13)
        ->and($build->isEasy())->toBeTrue()
        ->and($taper->hardMinutes)->toBe(10);
});

it('uses whole interval repetitions and the phase target', function (): void {
    $prescription = $this->resolver->resolve(SessionType::Interval, PlanPhase::Peak, null, null, PRESCRIPTION_PACES, IntentVerdict::Hit, 12);
    $rounded = $this->resolver->resolve(SessionType::Interval, PlanPhase::Peak, null, null, PRESCRIPTION_PACES, IntentVerdict::Unknown, 13);

    expect($prescription->hardMinutes)->toBe(16)
        ->and($rounded->hardMinutes)->toBe(12)
        ->and($prescription->paceBand)->toBe(PaceBand::Interval);
});

it('clips every quality type to whole work units when the weekly reserve binds', function (): void {
    $tempo = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Build, null, null, PRESCRIPTION_PACES, hardMinutesAvailable: 19);
    $interval = $this->resolver->resolve(SessionType::Interval, PlanPhase::Build, null, null, PRESCRIPTION_PACES, hardMinutesAvailable: 5);
    $long = $this->resolver->resolve(SessionType::Long, PlanPhase::Build, 42_195, 12_000, PRESCRIPTION_PACES, hardMinutesAvailable: 10);

    expect($tempo->hardMinutes)->toBe(15)
        ->and($interval->isEasy())->toBeTrue()
        ->and($long->isEasy())->toBeTrue();
});

it('uses the slower supported marathon pace and no race-specific work beyond the marathon', function (): void {
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
        ->and($ultra->raceContext)->toBeNull();
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

it('prescribes no race-specific hard work for an ultra goal', function (): void {
    $prescription = $this->resolver->resolve(SessionType::Long, PlanPhase::Peak, 50_000, 21_000, PRESCRIPTION_PACES);

    expect($prescription->isEasy())->toBeTrue()
        ->and($prescription->hardMinutes)->toBe(0);
});

it('keeps comparable evidence separate for threshold and race-specific work', function (): void {
    expect(IntensityPrescriptionResolver::familyKey(SessionType::Tempo, null, null))->toBe('tempo')
        ->and(IntensityPrescriptionResolver::familyKey(SessionType::Tempo, 42_195, 12_000))->toBe('race_tempo')
        ->and(IntensityPrescriptionResolver::familyKey(SessionType::Long, 42_195, 12_000))->toBe('race_long')
        ->and(IntensityPrescriptionResolver::familyKey(SessionType::Long, 50_000, 21_000))->toBe('long');
});

it('stores no reason for a day that prescribes no quality', function (): void {
    expect($this->resolver->resolve(SessionType::Rest, PlanPhase::Build, null, null, null)->reason)->toBeNull()
        ->and($this->resolver->resolve(SessionType::Easy, PlanPhase::Build, null, null, null)->reason)->toBeNull()
        ->and($this->resolver->resolve(SessionType::Long, PlanPhase::Base, null, null, null)->reason)->toBeNull();
});

it('never prescribes a Peak threshold block longer than the athlete could race at its pace', function (float $vdot): void {
    $paces = app(TrainingPaceCalculator::class)->fromVdot($vdot);
    $peak = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, null, null, $paces, IntentVerdict::Hit, 35);
    $blockMeters = $peak->hardMinutes * 60 / $peak->paceSecPerKm * 1000;

    expect($peak->hardMinutes)->toBe(35)
        ->and($peak->paceBand)->toBe(PaceBand::Threshold)
        ->and(app(VdotEstimator::class)->raceTimeForVdot($vdot, $blockMeters))->toBeLessThanOrEqual($peak->hardMinutes * 60.0);
})->with([35.0, 55.0]);

it('never prescribes a marathon-pace block faster than the VDOT marathon equivalent, whatever the goal time', function (SessionType $type, PlanPhase $phase): void {
    $prescription = $this->resolver->resolve($type, $phase, 42_195, 10_800, PRESCRIPTION_PACES);

    expect($prescription->paceBand === PaceBand::Marathon ? $prescription->paceSecPerKm : PRESCRIPTION_PACES['marathon'])
        ->toBeGreaterThanOrEqual(PRESCRIPTION_PACES['marathon']);
})->with([SessionType::Long, SessionType::Tempo])->with([PlanPhase::Base, PlanPhase::Build, PlanPhase::Peak, PlanPhase::Taper]);

it('targets goal-pace work from the table by kind and phase, halved when ambitious', function (string $kind, SessionType $type, PlanPhase $phase, RaceAmbitionState $band, int $minutes): void {
    $work = new GoalPaceWork($kind, 10_000, 285, $band);
    $prescription = $this->resolver->resolve($type, $phase, 10_000, 2850, PRESCRIPTION_PACES, IntentVerdict::Hit, 60, goalPace: $work);

    expect($prescription->hardMinutes)->toBe($minutes)
        ->and($prescription->paceSecPerKm)->toBe(285)
        ->and($prescription->raceContext)->toBe($work->context());
})->with([
    ['5k', SessionType::Interval, PlanPhase::Build, RaceAmbitionState::OnTrack, 15],
    ['5k', SessionType::Interval, PlanPhase::Peak, RaceAmbitionState::OnTrack, 20],
    ['5k', SessionType::Interval, PlanPhase::Taper, RaceAmbitionState::OnTrack, 10],
    ['5k', SessionType::Interval, PlanPhase::Taper, RaceAmbitionState::Ambitious, 4],
    ['10k', SessionType::Interval, PlanPhase::Build, RaceAmbitionState::OnTrack, 21],
    ['10k', SessionType::Tempo, PlanPhase::Peak, RaceAmbitionState::OnTrack, 24],
    ['10k', SessionType::Interval, PlanPhase::Taper, RaceAmbitionState::Ambitious, 6],
    ['half', SessionType::Tempo, PlanPhase::Build, RaceAmbitionState::OnTrack, 30],
    ['half', SessionType::Tempo, PlanPhase::Peak, RaceAmbitionState::Ambitious, 20],
    ['half', SessionType::Interval, PlanPhase::Taper, RaceAmbitionState::OnTrack, 20],
    ['marathon', SessionType::Tempo, PlanPhase::Peak, RaceAmbitionState::OnTrack, 35],
    ['marathon', SessionType::Tempo, PlanPhase::Peak, RaceAmbitionState::Ambitious, 17],
    ['marathon', SessionType::Long, PlanPhase::Peak, RaceAmbitionState::OnTrack, 40],
]);

it('runs goal-pace work at the goal pace, faster than the supported marathon pace', function (): void {
    $prescription = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, 42_195, 12_000, PRESCRIPTION_PACES, goalPace: new GoalPaceWork('marathon', 42_195, 284, RaceAmbitionState::OnTrack));

    expect($prescription->paceSecPerKm)->toBe(284)
        ->and($prescription->paceBand)->toBe(PaceBand::Marathon);
});

it('starts and steps 5K and 10K goal pace in whole reps, whatever the day\'s type', function (): void {
    $work = new GoalPaceWork('10k', 10_000, 300, RaceAmbitionState::OnTrack);
    $cold = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, 10_000, 3000, PRESCRIPTION_PACES, goalPace: $work);
    $stepped = $this->resolver->resolve(SessionType::Tempo, PlanPhase::Peak, 10_000, 3000, PRESCRIPTION_PACES, IntentVerdict::TooHard, 16, goalPace: $work);

    expect($cold->hardMinutes)->toBe(8)
        ->and($stepped->hardMinutes)->toBe(12);
});

it('keeps goal-pace history in its own family and the marathon in its race families', function (): void {
    expect(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Interval, ['kind' => '10k', 'band' => 'on_track']))->toBe('goal_pace')
        ->and(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Tempo, ['kind' => 'half', 'band' => 'ambitious']))->toBe('goal_pace')
        ->and(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Tempo, ['kind' => 'marathon', 'band' => 'on_track']))->toBe('race_tempo')
        ->and(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Long, ['kind' => 'marathon', 'band' => 'on_track']))->toBe('race_long')
        ->and(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Tempo, ['kind' => 'marathon']))->toBe('race_tempo')
        ->and(IntensityPrescriptionResolver::familyKeyForContext(SessionType::Interval, null))->toBe('interval');
});

it('keeps a time trial out of every progression family', function (SessionType $type): void {
    expect(IntensityPrescriptionResolver::familyKeyForContext($type, ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0]))->toBe('time_trial');
})->with([SessionType::Tempo, SessionType::Interval]);
