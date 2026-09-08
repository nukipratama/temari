<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\SegmentGenerator;

const PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];

// No race, a race below the marathon-pace threshold, and one at or above it —
// the three inputs `WeekPlanBuilder::isMarathonDistance()` distinguishes.
const RACE_DISTANCES = ['no race' => null, '10K' => 10_000.0, 'marathon' => 42_195.0];

it('returns no segments for a rest day', function (): void {
    expect(SegmentGenerator::generate(SessionType::Rest, PlanPhase::Base, null, false, 16.0, 1.0, PACES))->toBe([]);
});

it('returns no core km for a rest day', function (): void {
    expect(SegmentGenerator::coreKmFor(SessionType::Rest, true, 16.0, 1.0))->toBe(0.0);
});

it('gives an Easy day a single main segment, sized Medium when primary and Short otherwise', function (): void {
    $primary = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, null, true, 16.0, 1.0, PACES);
    $secondary = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, null, false, 16.0, 1.0, PACES);

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
    $segments = SegmentGenerator::generate(SessionType::Long, PlanPhase::Build, 42_195.0, false, 16.0, 1.0, PACES);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->key)->toBe(SegmentKey::Main)
        ->and($segments[0]->paceLabel)->toBe(PaceBand::Easy)
        // 16km at 360 sec/km = 96 minutes
        ->and($segments[0]->minutes)->toBe(96.0);
});

it('switches a Long day to Marathon pace only in Peak/Taper for a marathon-distance race', function (): void {
    $peakMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Peak, 42_195.0, false, 16.0, 1.0, PACES);
    $peakNonMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Peak, null, false, 16.0, 1.0, PACES);
    $buildMarathon = SegmentGenerator::generate(SessionType::Long, PlanPhase::Build, 42_195.0, false, 16.0, 1.0, PACES);

    expect($peakMarathon[0]->paceLabel)->toBe(PaceBand::Marathon)
        ->and($peakNonMarathon[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($buildMarathon[0]->paceLabel)->toBe(PaceBand::Easy);
});

it('carves a Tempo day\'s fixed 10min warmup out of its distance, leaving the rest at Threshold', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, null, false, 16.0, 1.0, PACES);

    // 16 * 0.65 = 10.4km for the whole outing. The 10min warmup at 360 sec/km
    // shows as 1.7km, leaving 8.7km. Build runs the threshold work as two
    // blocks around a 2min easy recovery.
    expect($segments)->toHaveCount(4)
        ->and($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->minutes)->toBe(10.0)
        ->and($segments[0]->paceLabel)->toBe(PaceBand::Easy)
        ->and($segments[0]->km)->toBe(1.7)
        ->and($segments[1]->key)->toBe(SegmentKey::Main)
        ->and($segments[1]->paceLabel)->toBe(PaceBand::Threshold)
        ->and($segments[1]->km)->toBe(4.2)
        ->and($segments[2]->key)->toBe(SegmentKey::Recovery)
        ->and($segments[2]->minutes)->toBe(2.0)
        ->and($segments[2]->paceLabel)->toBe(PaceBand::Easy)
        ->and($segments[3]->key)->toBe(SegmentKey::Main)
        ->and($segments[3]->km)->toBe(4.2);
});

it('progresses a Tempo day from several blocks to one continuous effort', function (): void {
    $blocks = fn (PlanPhase $phase): int => count(array_filter(
        SegmentGenerator::generate(SessionType::Tempo, $phase, null, false, 16.0, 1.0, PACES),
        fn ($s): bool => $s->key === SegmentKey::Main,
    ));

    expect($blocks(PlanPhase::Base))->toBe(3)
        ->and($blocks(PlanPhase::Build))->toBe(2)
        ->and($blocks(PlanPhase::Peak))->toBe(1)
        ->and($blocks(PlanPhase::Taper))->toBe(2);
});

it('keeps a Tempo day continuous when it is too small to break up', function (): void {
    // At the long-run floor in a reduced week the threshold work barely exists;
    // splitting it three ways would leave nothing in each block.
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Base, null, false, 3.0, 0.5, PACES);

    expect(array_filter($segments, fn ($s): bool => $s->key === SegmentKey::Recovery))->toBe([])
        ->and($segments)->toHaveCount(2);
});

it('spends the whole prescribed distance and no more, so the card and the run agree', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, null, false, 16.0, 1.0, PACES);

    $km = array_sum(array_map(
        fn ($s): float => $s->minutes * 60 / $s->paceSecPerKm,
        $segments,
    ));

    expect(round($km, 1))->toBe(SegmentGenerator::coreKmFor(SessionType::Tempo, false, 16.0, 1.0));
});

it('never lets the warmup swallow more than half a session too small to hold it', function (): void {
    // A 3km long-run floor in a reduced taper week: the 12min warmup alone
    // would be 2km of a 0.6km day.
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Taper, null, false, 3.0, 0.5, PACES);

    $warmupKm = $segments[0]->minutes * 60 / $segments[0]->paceSecPerKm;
    $dayKm = SegmentGenerator::coreKmFor(SessionType::Interval, false, 3.0, 0.5);

    expect($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($warmupKm)->toBeGreaterThan($dayKm)
        ->and(array_filter($segments, fn ($s): bool => $s->key === SegmentKey::Interval))->not->toBeEmpty();
});

it('switches a Tempo day to Marathon pace only in Peak/Taper for a marathon-distance race', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Taper, 42_195.0, false, 16.0, 1.0, PACES);

    expect($segments[1]->paceLabel)->toBe(PaceBand::Marathon);
});

it('does not scale a Tempo day\'s warmup when volumeScale changes, only its main set', function (): void {
    $scaled = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, null, false, 16.0, 1.0, PACES, volumeScale: 1.3);

    // 10.4 * 1.3 = 13.5km outing, less the same fixed 1.7km warmup, and the
    // 11.8km that leaves is split across Build's two threshold blocks.
    expect($scaled[0]->minutes)->toBe(10.0)
        ->and($scaled[0]->km)->toBe(1.7)
        ->and($scaled[1]->km)->toBe(5.7)
        ->and($scaled[3]->km)->toBe(5.8);
});

it('builds an Interval day as a warmup then alternating reps and recoveries, with no cooldown', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, null, false, 16.0, 1.0, PACES);

    // 16 * 0.40 = 6.4km outing, less a 2km warmup = 4.4km for the work. A Build
    // rep is 3min (0.75km at 240 sec/km) and its recovery 2min (0.333km at 360),
    // so round((4.4 + 0.333) / 1.083) = 4 reps and 3 recoveries between them.
    expect($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->minutes)->toBe(12.0);

    $body = array_slice($segments, 1);
    $intervalCount = count(array_filter($body, fn ($s) => $s->key === SegmentKey::Interval));
    $recoveryCount = count(array_filter($body, fn ($s) => $s->key === SegmentKey::Recovery));

    expect($intervalCount)->toBe(4)
        ->and($recoveryCount)->toBe(3) // one fewer recovery than reps — the day ends on work
        ->and($body[0]->key)->toBe(SegmentKey::Interval)
        ->and(end($body)->key)->toBe(SegmentKey::Interval)
        ->and($body[0]->minutes)->toBe(3.0)
        ->and($body[0]->paceLabel)->toBe(PaceBand::Interval)
        ->and($body[0]->zone)->toBe('Z5');
});

it('gives Interval days a longer rep and same recovery in Peak than in Build', function (): void {
    $build = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, null, false, 16.0, 1.0, PACES);
    $peak = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Peak, null, false, 16.0, 1.0, PACES);

    $buildRep = array_first(array_filter($build, fn ($s) => $s->key === SegmentKey::Interval));
    $peakRep = array_first(array_filter($peak, fn ($s) => $s->key === SegmentKey::Interval));

    expect($buildRep->minutes)->toBe(3.0)
        ->and($peakRep->minutes)->toBe(4.0);
});

it('gives Interval days shorter reps and longer recovery in Taper, sharpening rather than grinding', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Taper, null, false, 16.0, 1.0, PACES);

    $rep = array_first(array_filter($segments, fn ($s) => $s->key === SegmentKey::Interval));
    $recovery = array_first(array_filter($segments, fn ($s) => $s->key === SegmentKey::Recovery));

    expect($rep->minutes)->toBe(2.0)
        ->and($recovery->minutes)->toBe(3.0);
});

it('falls back to a single rep when no VDOT estimate exists to size the work budget', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, null, false, 16.0, 1.0, null);
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
        $segments = SegmentGenerator::generate($type, PlanPhase::Build, null, true, 16.0, 1.0, null);
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

it('rounds a session\'s segment distances so they add up to the figure on the card', function (): void {
    // Every long-run baseline in a realistic range, so a case where the two
    // parts each round the same way past the midpoint can't slip through --
    // and across every phase and race distance, since both reshape the day:
    // the phase decides how many blocks the threshold work is broken into,
    // and a marathon-distance race swaps the band the main set is run at.
    foreach (PlanPhase::cases() as $phase) {
        foreach (RACE_DISTANCES as $label => $raceDistanceM) {
            foreach (range(30, 250) as $tenths) {
                $longRunKm = $tenths / 10;
                $segments = SegmentGenerator::generate(SessionType::Tempo, $phase, $raceDistanceM, false, $longRunKm, 1.0, PACES);

                $shown = round(array_sum(array_map(fn ($s): float => $s->km, $segments)), 1);
                $headline = SegmentGenerator::coreKmFor(SessionType::Tempo, false, $longRunKm, 1.0);

                expect($shown)->toBe($headline, "{$phase->value}, {$label}, long_run_km {$longRunKm}");
            }
        }
    }
});

it('reports what an Interval day actually asks for, which its budget cannot always be', function (): void {
    // A whole number of fixed-duration reps rarely lands on an arbitrary
    // kilometre budget: this is quantisation, not a rounding slip, and it is
    // why prescribedKm() exists rather than the day reporting coreKmFor().
    $paces = ['easy' => 450, 'marathon' => 408, 'threshold' => 378, 'interval' => 354];
    $segments = SegmentGenerator::generate(SessionType::Interval, PlanPhase::Build, null, false, 9.1, 1.08, $paces);

    $budget = round(SegmentGenerator::coreKmFor(SessionType::Interval, false, 9.1, 1.08) * 1.0, 1);
    $asked = SegmentGenerator::prescribedKm($segments);

    expect($asked)->not->toBeNull()
        ->and($asked)->not->toBe($budget)
        ->and(abs($asked - $budget))->toBeLessThan(0.6);
});

it('reports a Tempo and an Easy day at exactly their budget', function (): void {
    foreach (PlanPhase::cases() as $phase) {
        foreach (RACE_DISTANCES as $label => $raceDistanceM) {
            foreach (range(30, 250) as $tenths) {
                $longRunKm = $tenths / 10;

                foreach ([SessionType::Tempo, SessionType::Easy, SessionType::Long] as $type) {
                    $segments = SegmentGenerator::generate($type, $phase, $raceDistanceM, false, $longRunKm, 1.0, PACES);

                    expect(SegmentGenerator::prescribedKm($segments))
                        ->toBe(SegmentGenerator::coreKmFor($type, false, $longRunKm, 1.0), "{$type->value} in {$phase->value}, {$label}, at {$longRunKm}");
                }
            }
        }
    }
});

it('reports no distance at all when no VDOT estimate can size the day', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, null, false, 16.0, 1.0, null);

    expect(SegmentGenerator::prescribedKm($segments))->toBeNull()
        ->and(SegmentGenerator::prescribedKm([]))->toBeNull();
});

it('leaves a bookend\'s distance null when no VDOT estimate can size it', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Tempo, PlanPhase::Build, null, false, 16.0, 1.0, null);

    $mainKm = array_sum(array_map(
        fn ($s): float => $s->km ?? 0.0,
        array_filter($segments, fn ($s): bool => $s->key === SegmentKey::Main),
    ));

    expect($segments[0]->key)->toBe(SegmentKey::Warmup)
        ->and($segments[0]->km)->toBeNull()
        // With nothing carved out, the threshold blocks still hold the whole day.
        ->and(round($mainKm, 1))->toBe(SegmentGenerator::coreKmFor(SessionType::Tempo, false, 16.0, 1.0));
});

it('gives a race day one block at the race distance, untouched by the volume multiplier', function (): void {
    $segments = SegmentGenerator::generate(SessionType::Race, PlanPhase::Taper, 21_097.0, false, 16.0, 0.5, PACES);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->key)->toBe(SegmentKey::Main)
        ->and($segments[0]->km)->toBe(21.1);
});

it('races a marathon at marathon pace and anything shorter at threshold', function (): void {
    $marathon = SegmentGenerator::generate(SessionType::Race, PlanPhase::Taper, 42_195.0, false, 16.0, 1.0, PACES);
    $tenK = SegmentGenerator::generate(SessionType::Race, PlanPhase::Taper, 10_000.0, false, 16.0, 1.0, PACES);

    expect($marathon[0]->paceLabel)->toBe(PaceBand::Marathon)
        ->and($tenK[0]->paceLabel)->toBe(PaceBand::Threshold);
});

it('sizes a race day from the race rather than the training baseline', function (): void {
    expect(SegmentGenerator::coreKmFor(SessionType::Race, false, 16.0, 1.0, 10_000.0))->toBe(10.0)
        ->and(SegmentGenerator::coreKmFor(SessionType::Race, false, 99.0, 2.0, 10_000.0))->toBe(10.0);
});

it('never scales a race by a redistributed week, since the event is the distance it is', function (): void {
    $full = SegmentGenerator::generate(SessionType::Race, PlanPhase::Taper, 10_000.0, false, 16.0, 1.0, PACES, 1.0);
    $scaled = SegmentGenerator::generate(SessionType::Race, PlanPhase::Taper, 10_000.0, false, 16.0, 1.0, PACES, 0.5);

    expect($scaled[0]->km)->toBe($full[0]->km);
});
