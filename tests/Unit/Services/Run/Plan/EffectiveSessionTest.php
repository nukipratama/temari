<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\Run\Plan\EffectiveSession;
use Illuminate\Support\Carbon;

function effectiveRow(array $attributes): PlannedSession
{
    return new PlannedSession()->forceFill($attributes);
}

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
