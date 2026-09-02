<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\SegmentGenerator;

const PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];

it('returns no segments for a rest day', function (): void {
    expect(SegmentGenerator::generate(SessionType::Rest, PlanPhase::Base, false, false, 16.0, 1.0, PACES))->toBe([]);
});

it('returns no core km for a rest day', function (): void {
    expect(SegmentGenerator::coreKmFor(SessionType::Rest, true, 16.0, 1.0))->toBe(0.0);
});

it('gives an Easy day a single main segment, sized Medium when primary and Short otherwise', function (): void {
    $primary = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, false, true, 16.0, 1.0, PACES);
    $secondary = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, false, false, 16.0, 1.0, PACES);

    expect($primary)->toHaveCount(1)
        ->and($primary[0]->key)->toBe(SegmentKey::Main)
        ->and($primary[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($primary[0]->zone)->toBe('Z2')
        // 16 * 0.65 = 10.4km at 360 sec/km = 62.4 minutes
        ->and($primary[0]->minutes)->toBe(62.4)
        // 16 * 0.40 = 6.4km at 360 sec/km = 38.4 minutes
        ->and($secondary[0]->minutes)->toBe(38.4);
});

it('gives a Long day a single main segment at Easy pace outside a marathon race-pace phase', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Long, PlanPhase::Build, true, false, 16.0, 1.0, PACES);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->key)->toBe(SegmentKey::Main)
        ->and($segments[0]->paceLabel)->toBe(PaceBand::Easy)
        // 16km at 360 sec/km = 96 minutes
        ->and($segments[0]->minutes)->toBe(96.0);
});

it('switches a Long day to Marathon pace only in Peak/Taper for a marathon-distance race', function (): void {
    $peakMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Peak, true, false, 16.0, 1.0, PACES);
    $peakNonMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Peak, false, false, 16.0, 1.0, PACES);
    $buildMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Build, true, false, 16.0, 1.0, PACES);

    expect($peakMarathon[0]->paceLabel)->toBe(PaceBand::Marathon)
        ->and($peakNonMarathon[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($buildMarathon[0]->paceLabel)->toBe(PaceBand::Easy);
});

it('gives a Tempo day a fixed 10min warmup and 5min cooldown around a Threshold main set', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, false, false, 16.0, 1.0, PACES);

    expect($segments)->toHaveCount(3)
        ->and($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->minutes)->toBe(10.0)
        ->and($segments[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($segments[1]->key)->toBe(SegmentKey::Main)
        ->and($segments[1]->paceLabel)->toBe(PaceBand::Threshold)
        // 16 * 0.65 = 10.4km at 270 sec/km = 46.8 minutes
        ->and($segments[1]->minutes)->toBe(46.8)
        ->and($segments[2]->key)->toBe(SegmentKey::Cooldown)
        ->and($segments[2]->minutes)->toBe(5.0);
});

it('switches a Tempo day to Marathon pace only in Peak/Taper for a marathon-distance race', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Taper, true, false, 16.0, 1.0, PACES);

    expect($segments[1]->paceLabel)->toBe(PaceBand::Marathon);
});

it('does not scale a Tempo day\'s warmup/cooldown when volumeScale changes, only its main set', function (): void {
    $scaled = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, false, false, 16.0, 1.0, PACES, volumeScale: 1.3);

    expect($scaled[0]->minutes)->toBe(10.0)
        ->and($scaled[2]->minutes)->toBe(5.0)
        // 10.4 * 1.3 = 13.52 -> 13.5km at 270 sec/km
        ->and($scaled[1]->minutes)->toBe(round(13.52 * 270 / 60, 1));
});

it('builds an Interval day as warmup, alternating interval/recovery reps, then cooldown', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, false, false, 16.0, 1.0, PACES);

    // 16 * 0.40 = 6.4km at 240 sec/km = 25.6 work minutes; Build reps are 3min -> round(25.6/3) = 9 reps
    expect($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->minutes)->toBe(12.0)
        ->and(end($segments)->key)->toBe(SegmentKey::Cooldown)
        ->and(end($segments)->minutes)->toBe(8.0);

    $body = array_slice($segments, 1, -1);
    $intervalCount = count(array_filter($body, fn ($s) => $s->key === SegmentKey::Interval));
    $recoveryCount = count(array_filter($body, fn ($s) => $s->key === SegmentKey::Recovery));

    expect($intervalCount)->toBe(9)
        ->and($recoveryCount)->toBe(8) // one fewer recovery than reps — no trailing recovery before cooldown
        ->and($body[0]->key)->toBe(SegmentKey::Interval)
        ->and(end($body)->key)->toBe(SegmentKey::Interval)
        ->and($body[0]->minutes)->toBe(3.0)
        ->and($body[0]->paceLabel)->toBe(PaceBand::Interval)
        ->and($body[0]->zone)->toBe('Z5');
});

it('gives Interval days a longer rep and same recovery in Peak than in Build', function (): void {
    $build = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, false, false, 16.0, 1.0, PACES);
    $peak = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Peak, false, false, 16.0, 1.0, PACES);

    $buildRep = array_first(array_filter($build, fn ($s) => $s->key === SegmentKey::Interval));
    $peakRep = array_first(array_filter($peak, fn ($s) => $s->key === SegmentKey::Interval));

    expect($buildRep->minutes)->toBe(3.0)
        ->and($peakRep->minutes)->toBe(4.0);
});

it('gives Interval days shorter reps and longer recovery in Taper, sharpening rather than grinding', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Taper, false, false, 16.0, 1.0, PACES);

    $rep = array_first(array_filter($segments, fn ($s) => $s->key === SegmentKey::Interval));
    $recovery = array_first(array_filter($segments, fn ($s) => $s->key === SegmentKey::Recovery));

    expect($rep->minutes)->toBe(2.0)
        ->and($recovery->minutes)->toBe(3.0);
});

it('falls back to a single rep when no VDOT estimate exists to size the work budget', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, false, false, 16.0, 1.0, null);
    $reps = array_values(array_filter($segments, fn ($s) => $s->key === SegmentKey::Interval));

    // Every segment's minutes here comes from a fixed table (warmup/cooldown,
    // and a rep's own length per phase) — none of that needs pace, only the
    // aggregate REP COUNT does (work-budget minutes ÷ rep length), so losing
    // VDOT only collapses the count to a single rep, it doesn't null anyone's
    // minutes. Only the pace each segment would run at goes unknown.
    expect($reps)->toHaveCount(1)
        ->and($reps[0]->minutes)->toBe(3.0)
        ->and($reps[0]->paceSecPerKm)->toBeNull()
        ->and($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->minutes)->toBe(12.0)
        ->and($segments[0]->paceSecPerKm)->toBeNull();
});

it('renders a null main-set minutes/pace with no VDOT estimate, keeping fixed warmup/cooldown minutes', function (): void {
    foreach ([SessionType::Easy, SessionType::Long, SessionType::Tempo] as $type) {
        $segments = SegmentGenerator::generate($type, PlanPhase::Build, false, true, 16.0, 1.0, null);
        foreach ($segments as $segment) {
            expect($segment->paceSecPerKm)->toBeNull();
            if ($segment->key === SegmentKey::Main) {
                expect($segment->minutes)->toBeNull();
            } else {
                expect($segment->minutes)->not->toBeNull();
            }
        }
    }
});

it('coreKmFor stays available with no VDOT estimate at all — it never needs pace', function (): void {
    // The headline distance_km figure (PlanRenderer::dayPayload()) is built
    // from this, deliberately independent of $paces — a brand new user with
    // no PR history yet still sees a real target, same guarantee DistanceBandKm
    // gave before this class existed.
    expect(SegmentGenerator::coreKmFor(SessionType::Tempo, false, 16.0, 1.0))->toBe(10.4);
});
