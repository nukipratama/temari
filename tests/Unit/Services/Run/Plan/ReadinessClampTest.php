<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\ReadinessClamp;
use App\Services\Run\Plan\SegmentGenerator;

const CLAMP_PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];
const CLAMP_BASELINE_KM = 16.0;
const CLAMP_MULTIPLIER = 1.0;

function applyClamp(SessionType $type, ReadinessCeiling $ceiling): ?array
{
    return ReadinessClamp::apply($type, PlanPhase::Build, false, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, CLAMP_PACES, $ceiling);
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

it('ModerateOk clamps quality work down to easy, at its own original size, but leaves a long day alone', function (): void {
    expect(applyClamp(SessionType::Long, ReadinessCeiling::ModerateOk))->toBeNull();

    $clamp = applyClamp(SessionType::Tempo, ReadinessCeiling::ModerateOk);
    $expectedSegments = SegmentGenerator::easyEquivalentOf(SessionType::Tempo, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, CLAMP_PACES);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['segments'])->toEqual($expectedSegments)
        ->and($clamp['segments'])->toHaveCount(1)
        ->and($clamp['segments'][0]->key)->toBe(SegmentKey::Main)
        ->and($clamp['note'])->toBeString()->not->toBe('');
});

it('EasyOnly scales a long day down to a shorter easy run, sized Medium like the week\'s primary Easy day', function (): void {
    $clamp = applyClamp(SessionType::Long, ReadinessCeiling::EasyOnly);
    $expectedSegments = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, false, true, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, CLAMP_PACES);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['segments'])->toEqual($expectedSegments);
});

it('EasyOnly scales quality work down to a short easy run', function (): void {
    $clamp = applyClamp(SessionType::Interval, ReadinessCeiling::EasyOnly);
    $expectedSegments = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, false, false, CLAMP_BASELINE_KM, CLAMP_MULTIPLIER, CLAMP_PACES);

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
