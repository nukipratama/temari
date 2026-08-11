<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Plan\ReadinessClamp;

it('never clamps anything under the optimistic QualityOk ceiling', function (): void {
    foreach ([SessionType::Rest, SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval] as $type) {
        expect(ReadinessClamp::apply($type, DistanceBand::Medium, ReadinessCeiling::QualityOk))->toBeNull();
    }
});

it('never clamps a rest day, since nothing is more restrictive than rest', function (): void {
    foreach ([ReadinessCeiling::Rest, ReadinessCeiling::EasyOnly, ReadinessCeiling::ModerateOk] as $ceiling) {
        expect(ReadinessClamp::apply(SessionType::Rest, DistanceBand::Rest, $ceiling))->toBeNull();
    }
});

it('never clamps easy, since it only needs the floor above rest', function (): void {
    foreach ([ReadinessCeiling::EasyOnly, ReadinessCeiling::ModerateOk] as $ceiling) {
        expect(ReadinessClamp::apply(SessionType::Easy, DistanceBand::Medium, $ceiling))->toBeNull();
    }
});

it('ModerateOk clamps quality work down to easy but leaves a long day alone', function (): void {
    expect(ReadinessClamp::apply(SessionType::Long, DistanceBand::Long, ReadinessCeiling::ModerateOk))->toBeNull();

    $clamp = ReadinessClamp::apply(SessionType::Tempo, DistanceBand::Medium, ReadinessCeiling::ModerateOk);
    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['distance_band'])->toBe(DistanceBand::Medium)
        ->and($clamp['pace_band'])->toBe(PaceBand::Easy)
        ->and($clamp['note'])->toBeString()->not->toBe('');
});

it('EasyOnly scales a long day down to a shorter easy run', function (): void {
    $clamp = ReadinessClamp::apply(SessionType::Long, DistanceBand::Long, ReadinessCeiling::EasyOnly);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['distance_band'])->toBe(DistanceBand::Medium)
        ->and($clamp['pace_band'])->toBe(PaceBand::Easy);
});

it('EasyOnly scales quality work down to a short easy run', function (): void {
    $clamp = ReadinessClamp::apply(SessionType::Interval, DistanceBand::Short, ReadinessCeiling::EasyOnly);

    expect($clamp['session_type'])->toBe(SessionType::Easy)
        ->and($clamp['distance_band'])->toBe(DistanceBand::Short)
        ->and($clamp['pace_band'])->toBe(PaceBand::Easy);
});

it('Rest clamps every non-rest session to a full rest day', function (): void {
    foreach ([SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval] as $type) {
        $clamp = ReadinessClamp::apply($type, DistanceBand::Medium, ReadinessCeiling::Rest);

        expect($clamp['session_type'])->toBe(SessionType::Rest)
            ->and($clamp['distance_band'])->toBe(DistanceBand::Rest)
            ->and($clamp['pace_band'])->toBeNull()
            ->and($clamp['note'])->toBeString()->not->toBe('');
    }
});

it('gives distinct notes for a long-run downgrade versus a quality-work downgrade', function (): void {
    $longNote = ReadinessClamp::apply(SessionType::Long, DistanceBand::Long, ReadinessCeiling::Rest)['note'];
    $tempoNote = ReadinessClamp::apply(SessionType::Tempo, DistanceBand::Medium, ReadinessCeiling::Rest)['note'];

    expect($longNote)->not->toBe($tempoNote);
});
