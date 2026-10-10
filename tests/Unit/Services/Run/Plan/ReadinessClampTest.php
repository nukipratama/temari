<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Enums\PaceBand;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\ReadinessClamp;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TimeTrial;

const CLAMP_PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];
const CLAMP_BASELINE_KM = 16.0;
const CLAMP_MULTIPLIER = 1.0;

function applyClamp(SessionType $type, ReadinessCeiling $ceiling): ?array
{
    return ReadinessClamp::apply($type, PlanPhase::Build, null, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, INF, CLAMP_PACES, $ceiling);
}

it('never clamps anything under the optimistic QualityOk ceiling', function (): void {
    foreach ([SessionType::Rest, SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval] as $type) {
        expect(applyClamp($type, ReadinessCeiling::QualityOk))->toBeNull();
    }
});

it('never clamps a rest day, since nothing is more restrictive than rest', function (): void {
    foreach ([ReadinessCeiling::Rest, ReadinessCeiling::EasyOnly, ReadinessCeiling::ModerateOk] as $ceiling) {
        expect(applyClamp(SessionType::Rest, $ceiling))->toBeNull();
    }
});

it('never clamps easy, since it only needs the floor above rest', function (): void {
    foreach ([ReadinessCeiling::EasyOnly, ReadinessCeiling::ModerateOk] as $ceiling) {
        expect(applyClamp(SessionType::Easy, $ceiling))->toBeNull();
    }
});

it('ModerateOk leaves long days alone and reduces a quality day without replacing its type', function (): void {
    expect(applyClamp(SessionType::Long, ReadinessCeiling::ModerateOk))->toBeNull();

    $prescription = new IntensityPrescription(20, PaceBand::Threshold, 270, 'planned quality');
    $clamp = ReadinessClamp::apply(
        SessionType::Tempo,
        PlanPhase::Build,
        null,
        CLAMP_BASELINE_KM,
        CLAMP_MULTIPLIER,
        INF,
        CLAMP_PACES,
        ReadinessCeiling::ModerateOk,
        reasons: ['demanding_session_within_24h'],
        prescription: $prescription,
    );
    $hardSegments = array_filter($clamp['segments'], static fn ($segment): bool => $segment->paceLabel !== PaceBand::Easy);

    expect($clamp['session_type'])->toBe(SessionType::Tempo)
        ->and(array_sum(array_map(static fn ($segment): float => $segment->minutes ?? 0.0, $hardSegments)))->toBe(15.0)
        ->and($clamp['quality_dose'])->toMatchArray([
            'hard_minutes' => 15,
            'original_hard_minutes' => 20,
            'pace_band' => 'threshold',
            'pace_sec_per_km' => 270,
        ])
        ->and($clamp['note'])->toContain('demanding session within the last day');
});

function applyQualityClamp(IntensityPrescription $prescription, array $reasons, SessionType $type = SessionType::Tempo, ReadinessCeiling $ceiling = ReadinessCeiling::ModerateOk): ?array
{
    return ReadinessClamp::apply($type, PlanPhase::Build, null, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, INF, CLAMP_PACES, $ceiling, reasons: $reasons, prescription: $prescription);
}

it('eases a time trial to an easy run of the trial distance at ModerateOk', function (array $reasons): void {
    $trial = new TimeTrial(5_000, 1_500);
    $clamp = applyQualityClamp($trial->prescription(), $reasons, SessionType::Interval);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['core_km'])->toBe(5.0)
        ->and($clamp)->not->toHaveKey('quality_dose')
        ->and($clamp['segments'][0]->paceLabel)->toBe(PaceBand::Easy);
})->with([
    'ahead of plan' => [['running_ahead_of_plan']],
    'demanding session' => [['demanding_session_within_24h']],
]);

it('falls back to the generic note for a retired reason code from an older snapshot', function (string $reason): void {
    expect(ReadinessClamp::noteFor(SessionType::Interval, ReadinessCeiling::Rest, [$reason]))
        ->toBe(ReadinessClamp::noteFor(SessionType::Interval, ReadinessCeiling::Rest))
        ->and(ReadinessClamp::paceEaseNote([$reason]))->toBe(ReadinessClamp::paceEaseNote());
})->with([
    'pain' => ['concerning_pain_reported'],
    'illness' => ['illness_reported'],
    'severe fatigue' => ['severe_fatigue_or_soreness_reported'],
    'mild sleep' => ['fair_sleep_with_load_support'],
    'stale feedback' => ['stale_recovery_feedback_not_applied'],
]);

it('preserves the event distance and gives a conservative effort note at ModerateOk', function (): void {
    $clamp = ReadinessClamp::apply(
        SessionType::Race,
        PlanPhase::Taper,
        10_000,
        CLAMP_BASELINE_KM,
        CLAMP_MULTIPLIER,
        INF,
        CLAMP_PACES,
        ReadinessCeiling::ModerateOk,
        reasons: ['volume_increased_sharply'],
    );

    expect($clamp['session_type'])->toBe(SessionType::Race)
        ->and($clamp['core_km'])->toBe(10.0)
        ->and(SegmentGenerator::segmentSumKm($clamp['segments']))->toBe(10.0)
        ->and($clamp['note'])->toContain('event distance');
});

it('reports the whole interval repetitions actually offered and falls back when none can be reduced', function (): void {
    foreach ([14 => 9, 3 => 0] as $original => $expected) {
        $clamp = ReadinessClamp::apply(
            SessionType::Interval,
            PlanPhase::Build,
            null,
            CLAMP_BASELINE_KM,
            1.0,
            INF,
            CLAMP_PACES,
            ReadinessCeiling::ModerateOk,
            prescription: new IntensityPrescription($original, PaceBand::Interval, 240, null)
        );
        $minutes = array_sum(array_map(static fn ($segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : ($segment->minutes ?? 0.0), $clamp['segments']));
        expect($minutes)->toBe((float) $expected);
        if ($expected > 0) {
            expect($clamp['quality_dose']['hard_minutes'])->toBe($expected)
                ->and($clamp['note'])->toContain("{$expected} hard minutes");
        } else {
            expect($clamp['session_type'])->toBe(SessionType::Easy)->and($clamp)->not->toHaveKey('quality_dose');
        }
    }
});

it('EasyOnly scales a long day down to a shorter easy run, sized Medium like the week\'s primary Easy day', function (): void {
    $clamp = applyClamp(SessionType::Long, ReadinessCeiling::EasyOnly);
    $expectedSegments = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, null, true, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, INF, CLAMP_PACES);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['segments'])->toEqual($expectedSegments);
});

it('never turns a progression-capped Long into a longer Easy run', function (): void {
    $clamp = ReadinessClamp::apply(
        SessionType::Long,
        PlanPhase::Build,
        null,
        CLAMP_BASELINE_KM,
        CLAMP_MULTIPLIER,
        INF,
        CLAMP_PACES,
        ReadinessCeiling::EasyOnly,
        longRunProgressionCapKm: 5.0,
    );

    expect($clamp['core_km'])->toBe(5.0)
        ->and(SegmentGenerator::segmentSumKm($clamp['segments']))->toBe(5.0);
});

it('EasyOnly scales quality work down to a short easy run', function (): void {
    $clamp = applyClamp(SessionType::Interval, ReadinessCeiling::EasyOnly);
    $expectedSegments = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, null, false, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, INF, CLAMP_PACES);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['segments'])->toEqual($expectedSegments);
});

it('a Long-downgrade is bigger than a Tempo/Interval-downgrade under EasyOnly', function (): void {
    $fromLong = applyClamp(SessionType::Long, ReadinessCeiling::EasyOnly);
    $fromTempo = applyClamp(SessionType::Tempo, ReadinessCeiling::EasyOnly);

    expect($fromLong['segments'][0]->minutes)->toBeGreaterThan($fromTempo['segments'][0]->minutes);
});

it('Rest clamps every non-rest session to a full rest day with no segments', function (): void {
    foreach ([SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval] as $type) {
        $clamp = applyClamp($type, ReadinessCeiling::Rest);

        expect($clamp['session_type'])->toBe(SessionType::Rest)
            ->and($clamp['segments'])->toBe([])
            ->and($clamp['note'])->toBeString()->not->toBe('');
    }
});

it('gives distinct notes for a long-run downgrade versus a quality-work downgrade', function (): void {
    $longNote = applyClamp(SessionType::Long, ReadinessCeiling::Rest)['note'];
    $tempoNote = applyClamp(SessionType::Tempo, ReadinessCeiling::Rest)['note'];

    expect($longNote)->not->toBe($tempoNote);
});

it('clampsToRest only when the ceiling bottoms out and the session asks for more', function (): void {
    expect(ReadinessClamp::clampsToRest(SessionType::Interval, ReadinessCeiling::Rest))->toBeTrue()
        ->and(ReadinessClamp::clampsToRest(SessionType::Long, ReadinessCeiling::Rest))->toBeTrue()
        ->and(ReadinessClamp::clampsToRest(SessionType::Easy, ReadinessCeiling::Rest))->toBeTrue()
        // A rest day already fits under a Rest ceiling, so nothing is downgraded.
        ->and(ReadinessClamp::clampsToRest(SessionType::Rest, ReadinessCeiling::Rest))->toBeFalse()
        // Every other ceiling downgrades to Easy at worst, never to a full rest.
        ->and(ReadinessClamp::clampsToRest(SessionType::Interval, ReadinessCeiling::EasyOnly))->toBeFalse()
        ->and(ReadinessClamp::clampsToRest(SessionType::Interval, ReadinessCeiling::ModerateOk))->toBeFalse()
        ->and(ReadinessClamp::clampsToRest(SessionType::Interval, ReadinessCeiling::QualityOk))->toBeFalse();
});

/** The predicate has to agree with what apply() would actually return. */
it('clampsToRest agrees with apply for every session type at the Rest ceiling', function (): void {
    foreach (SessionType::cases() as $type) {
        $clamped = ReadinessClamp::apply($type, PlanPhase::Base, null, 20.0, 1.0, INF, null, ReadinessCeiling::Rest);

        expect(ReadinessClamp::clampsToRest($type, ReadinessCeiling::Rest))
            ->toBe($clamped !== null && $clamped['session_type'] === SessionType::Rest);
    }
});

it('leaves a race alone when readiness is clear but allows a rest advisory', function (): void {
    expect(applyClamp(SessionType::Race, ReadinessCeiling::QualityOk))->toBeNull()
        ->and(ReadinessClamp::clampsToRest(SessionType::Race, ReadinessCeiling::QualityOk))->toBeFalse()
        ->and(ReadinessClamp::downgradeFor(SessionType::Race, ReadinessCeiling::QualityOk))->toBeNull();

    $clamp = ReadinessClamp::apply(
        SessionType::Race,
        PlanPhase::Build,
        42_195.0,
        CLAMP_BASELINE_KM,
        CLAMP_MULTIPLIER,
        INF,
        CLAMP_PACES,
        ReadinessCeiling::Rest,
    );

    expect($clamp['session_type'])->toBe(SessionType::Rest)
        ->and(ReadinessClamp::clampsToRest(SessionType::Race, ReadinessCeiling::Rest))->toBeTrue()
        ->and(ReadinessClamp::downgradeFor(SessionType::Race, ReadinessCeiling::Rest))->toBe(SessionType::Rest);
});

it('paceEaseApplies only for an Easy day at EasyOnly and a Long day at ModerateOk', function (): void {
    foreach (SessionType::cases() as $type) {
        foreach (ReadinessCeiling::cases() as $ceiling) {
            $expected = ($type === SessionType::Easy && $ceiling === ReadinessCeiling::EasyOnly)
                || ($type === SessionType::Long && $ceiling === ReadinessCeiling::ModerateOk);

            expect(ReadinessClamp::paceEaseApplies($type, $ceiling))
                ->toBe($expected, "{$type->value} under {$ceiling->value}");
        }
    }
});

/** One lever per day: apply() already downgrades every case paceEaseApplies() would otherwise also fire on. */
it('paceEaseApplies never overlaps with a day apply() already clamps', function (): void {
    foreach (SessionType::cases() as $type) {
        foreach (ReadinessCeiling::cases() as $ceiling) {
            if (ReadinessClamp::paceEaseApplies($type, $ceiling)) {
                expect(applyClamp($type, $ceiling))->toBeNull();
            }
        }
    }
});

it('paceEaseNote is a non-empty templated string', function (): void {
    expect(ReadinessClamp::paceEaseNote())->toBeString()->not->toBe('');
});

// noteFor() is the same explanation apply() builds, reached without a segment
// list. The pair only stays honest if every combination agrees, including which
// ones have nothing to explain at all.
it('gives the same note as apply for every session and ceiling', function (): void {
    foreach (SessionType::cases() as $type) {
        foreach (ReadinessCeiling::cases() as $ceiling) {
            expect(ReadinessClamp::noteFor($type, $ceiling))
                ->toBe(applyClamp($type, $ceiling)['note'] ?? null, "{$type->value} under {$ceiling->value}");
        }
    }
});
