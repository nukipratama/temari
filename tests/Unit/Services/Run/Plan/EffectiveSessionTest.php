<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Enums\PaceBand;
use App\Models\PlannedSession;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\SessionSegment;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\IntentVerdict;
use App\Models\ActivityDetail;
use App\Services\Run\Plan\SessionIntentJudge;
use Illuminate\Support\Carbon;

function effectiveRow(array $attributes): PlannedSession
{
    return new PlannedSession()->forceFill($attributes);
}

it('uses the known current marathon band and context when composing an older threshold dose', function (): void {
    $context = ['distance_m' => 42195, 'goal_pace_sec_per_km' => 330, 'kind' => 'marathon'];
    $effective = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 12,
        'prescribed_pace_band' => PaceBand::Marathon, 'prescribed_pace_sec_per_km' => 330,
        'prescription_race_context' => $context, 'clamped_km' => 8.0,
        'readiness_assessment' => ['adjustment' => ['quality_dose' => [
            'hard_minutes' => 15, 'original_hard_minutes' => 20,
            'pace_band' => 'threshold', 'pace_sec_per_km' => 270,
        ]]],
    ]), 8.0);
    $paces = ['easy' => 360, 'marathon' => 330, 'threshold' => 270, 'interval' => 240];
    $segments = SegmentGenerator::forPrescription(SessionType::Tempo, PlanPhase::Build, $effective->coreKm, $paces, $effective->qualityPrescription());
    $run = new ActivityDetail()->forceFill(['distance' => 8000, 'elapsed_time' => 3600,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 48, 'Z3' => 12, 'Z4' => 0, 'Z5' => 0]],
    ]);
    $main = array_find($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Main);
    expect(SessionIntentJudge::judge(SessionType::Tempo, $segments, $paces, [$run])['verdict'])->toBe(IntentVerdict::Hit)
        ->and($main->paceLabel)->toBe(PaceBand::Marathon)
        ->and($main->zone)->toBe('Z3')
        ->and($effective->qualityPrescription()->raceContext)->toEqual($context);
});

it('preserves recorded modern distance when historical core reconstruction changes', function (): void {
    $effective = EffectiveSession::of(effectiveRow([
        'date' => '2026-09-07', 'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold,
        'clamped_km' => 7.0,
        'readiness_assessment' => ['adjustment' => ['quality_dose' => [
            'hard_minutes' => 15, 'original_hard_minutes' => 20,
            'pace_band' => 'threshold', 'pace_sec_per_km' => 270,
        ]]],
    ]), 6.0);
    expect($effective->coreKm)->toBe(7.0)
        ->and($effective->qualityPrescription()?->hardMinutes)->toBe(15);
});

it('composes a saved readiness dose with a tighter base workload cap', function (): void {
    $effective = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 12,
        'prescribed_pace_band' => PaceBand::Threshold, 'prescribed_pace_sec_per_km' => 280,
        'clamped_km' => 8.0,
        'readiness_assessment' => ['adjustment' => ['quality_dose' => [
            'hard_minutes' => 15, 'original_hard_minutes' => 20,
            'pace_band' => 'threshold', 'pace_sec_per_km' => 270,
        ]]],
    ]), 7.0);
    expect($effective->qualityPrescription()?->hardMinutes)->toBe(12)
        ->and($effective->qualityPrescription()?->paceSecPerKm)->toBe(280)
        ->and($effective->coreKm)->toBe(8.0);
    $segments = SegmentGenerator::forPrescription(
        SessionType::Tempo,
        PlanPhase::Build,
        $effective->coreKm,
        ['easy' => 360, 'marathon' => 300, 'threshold' => 280, 'interval' => 240],
        $effective->qualityPrescription()
    );
    expect(array_sum(array_map(static fn (SessionSegment $segment): float => $segment->key === SegmentKey::Main ? ($segment->minutes ?? 0.0) : 0.0, $segments)))->toBe(12.0);
});

it('drops a saved hard dose when the new base prescription is easy', function (): void {
    $effective = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Easy, 'prescribed_hard_minutes' => 0, 'clamped_km' => 8.0,
        'readiness_assessment' => ['adjustment' => ['quality_dose' => [
            'hard_minutes' => 15, 'original_hard_minutes' => 20,
            'pace_band' => 'threshold', 'pace_sec_per_km' => 270,
        ]]],
    ]), 5.0);
    expect($effective->qualityPrescription())->toBeNull()
        ->and($effective->sessionType)->toBe(SessionType::Easy)
        ->and($effective->coreKm)->toBe(8.0);
});

it('is the stored session when no clamp was recorded', function (): void {
    $effective = EffectiveSession::of(effectiveRow(['session_type' => SessionType::Tempo]), 6.4);

    expect($effective->sessionType)->toBe(SessionType::Tempo)
        ->and($effective->coreKm)->toBe(6.4)
        ->and($effective->isEased())->toBeFalse()
        ->and($effective->easedFromType)->toBeNull()
        ->and($effective->easedFromKm)->toBeNull();
});

it('is an easy run at the recorded distance on a day eased to it', function (): void {
    $effective = EffectiveSession::of(effectiveRow(['session_type' => SessionType::Tempo, 'clamped_km' => 3.6]), 5.9);

    expect($effective->sessionType)->toBe(SessionType::Easy)
        ->and($effective->coreKm)->toBe(3.6)
        ->and($effective->isEased())->toBeTrue()
        ->and($effective->easedFromType)->toBe(SessionType::Tempo)
        ->and($effective->easedFromKm)->toBe(5.9)
        ->and($effective->distanceHeld())->toBeFalse();
});

it('keeps a recorded readiness dose reduction in its original quality type', function (): void {
    $qualityDose = [
        'hard_minutes' => 15,
        'original_hard_minutes' => 20,
        'pace_band' => PaceBand::Threshold->value,
        'pace_sec_per_km' => 270,
    ];
    $effective = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Tempo,
        'clamped_km' => 6.4,
        'readiness_assessment' => ['adjustment' => ['quality_dose' => $qualityDose]],
    ]), 6.4);

    expect($effective->sessionType)->toBe(SessionType::Tempo)
        ->and($effective->coreKm)->toBe(6.4)
        ->and($effective->qualityDose)->toBe($qualityDose)
        ->and($effective->isEased())->toBeTrue()
        ->and($effective->distanceHeld())->toBeTrue();
});

it('is a rest day on a day clamped to rest', function (): void {
    $effective = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Long,
        'rest_clamped_at' => Carbon::parse('2026-09-15 00:01:00'),
    ]), 12.0);

    expect($effective->sessionType)->toBe(SessionType::Rest)
        ->and($effective->coreKm)->toBe(0.0)
        ->and($effective->easedFromType)->toBe(SessionType::Long)
        ->and($effective->easedFromKm)->toBe(12.0)
        ->and($effective->distanceHeld())->toBeFalse();
});

it('holds the distance when only the intensity was eased', function (): void {
    $effective = EffectiveSession::of(effectiveRow(['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]), 6.4);

    expect($effective->sessionType)->toBe(SessionType::Easy)
        ->and($effective->distanceHeld())->toBeTrue();
});

it('reads an un-eased session as holding nothing', function (): void {
    expect(EffectiveSession::of(effectiveRow(['session_type' => SessionType::Easy]), 4.0)->distanceHeld())->toBeFalse();
});

it('keeps the stored type and distance on a day only pace-eased, and exposes the eased pace', function (): void {
    $effective = EffectiveSession::of(effectiveRow(['session_type' => SessionType::Long, 'eased_pace_sec_per_km' => 375]), 20.0);

    expect($effective->sessionType)->toBe(SessionType::Long)
        ->and($effective->coreKm)->toBe(20.0)
        ->and($effective->isPaceEased())->toBeTrue()
        ->and($effective->easedPaceSecPerKm)->toBe(375)
        ->and($effective->isEased())->toBeFalse()
        ->and($effective->easedFromType)->toBeNull()
        ->and($effective->easedFromKm)->toBeNull();
});

it('reads a session with no pace ease recorded as not pace-eased', function (): void {
    expect(EffectiveSession::of(effectiveRow(['session_type' => SessionType::Easy]), 4.0)->isPaceEased())->toBeFalse();
});

/** A rest clamp and a distance ease are each their own lever; a pace ease never rides along on top. */
it('a rest clamp or a recorded distance ease takes priority over a pace ease field', function (): void {
    $restClamped = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Long,
        'rest_clamped_at' => Carbon::now(),
        'eased_pace_sec_per_km' => 375,
    ]), 20.0);
    $distanceEased = EffectiveSession::of(effectiveRow([
        'session_type' => SessionType::Tempo,
        'clamped_km' => 3.6,
        'eased_pace_sec_per_km' => 375,
    ]), 5.9);

    expect($restClamped->isPaceEased())->toBeFalse()
        ->and($distanceEased->isPaceEased())->toBeFalse();
});
