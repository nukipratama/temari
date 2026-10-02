<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Freeze "today" so Carbon::today() math is stable; afterEach prevents leak on failure.
beforeEach(function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $this->load = new TrainingLoad();
});
afterEach(fn () => Carbon::setTestNow());

function seedTrimpDay(User $user, ?float $trimp, int $daysAgo): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'start_date_local' => Carbon::today()->subDays($daysAgo),
    ]);
}

function seedUnanalyzedTrimpDay(User $user, ?float $trimp, int $daysAgo): void
{
    $activity = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'start_date_local' => Carbon::today()->subDays($daysAgo),
    ]);
}

/**
 * @param  array<string, float>  $dailyTrimp
 * @return array<string, true>
 */
function runDaysOf(array $dailyTrimp): array
{
    return array_map(fn (): bool => true, $dailyTrimp);
}

it('computes Edwards TRIMP as zone-weighted minute sum', function (): void {
    // 1*5 + 2*30 + 3*20 + 4*5 + 5*0 = 145
    $trimp = $this->load->edwardsTrimp([
        'Z1' => 5,
        'Z2' => 30,
        'Z3' => 20,
        'Z4' => 5,
        'Z5' => 0,
    ]);

    expect($trimp)->toEqualWithDelta(145.0, 0.01);
});

it('handles missing zones', function (): void {
    expect($this->load->edwardsTrimp(['Z2' => 60]))->toEqualWithDelta(120.0, 0.01);
});

it('a tempo (Z4-heavy) outscores an easy (Z2-heavy) of the same duration', function (): void {
    $easy = $this->load->edwardsTrimp(['Z2' => 60]);
    $tempo = $this->load->edwardsTrimp(['Z1' => 5, 'Z2' => 10, 'Z4' => 40, 'Z5' => 5]);

    expect($tempo)->toBeGreaterThan($easy * 1.5);
});

it('uses narrow thresholds for low-CTL beginners', function (): void {
    expect($this->load->formStatus(0, 10))->toBe('optimal')
        ->and($this->load->formStatus(-6, 10))->toBe('fatigued')
        ->and($this->load->formStatus(-15, 10))->toBe('overreaching');
});

it('uses moderate thresholds for mid-CTL runners', function (): void {
    expect($this->load->formStatus(0, 40))->toBe('optimal')
        ->and($this->load->formStatus(-20, 40))->toBe('fatigued')
        ->and($this->load->formStatus(-35, 40))->toBe('overreaching');
});

it('uses wide thresholds for veteran runners', function (): void {
    expect($this->load->formStatus(0, 60))->toBe('optimal')
        ->and($this->load->formStatus(-25, 60))->toBe('fatigued')
        ->and($this->load->formStatus(-50, 60))->toBe('overreaching')
        ->and($this->load->formStatus(25, 60))->toBe('fresh');
});

// Regression for #1009 (reopened): a narrator prompt used to spell out
// "form (CTL - ATL): positive = fresh, negative = fatigued" and hand the
// model the bare signed form. formRelation() resolves that sign server-side.
it('resolves formRelation from the sign of form alone, independent of formStatus', function (): void {
    expect(TrainingLoad::formRelation(8.0))->toBe('fresh')
        ->and(TrainingLoad::formRelation(-8.0))->toBe('fatigued')
        ->and(TrainingLoad::formRelation(0.0))->toBe('balanced');
});

it('returns null when the user has no TRIMP-bearing activities', function (): void {
    $user = User::factory()->make(['id' => 1]);
    expect($this->load->summary($user))->toBeNull();
});

it('does not roll future scored days into a pre-HR load date', function (): void {
    $map = ['2026-05-11' => 120.0];
    expect($this->load->summaryFromDailyMap($map, runDaysOf($map), Carbon::parse('2026-05-10')))->toBeNull()
        ->and($this->load->summaryFromDailyMap($map, runDaysOf($map), Carbon::parse('2026-05-17'), Carbon::parse('2026-05-10')))->toBeNull()
        ->and($this->load->summaryFromDailyMap($map, runDaysOf($map), Carbon::parse('2026-05-11'))['ctl_42d'])->toBeGreaterThan(0.0);
});

it('reads ATL and CTL from a series rolled past the load date exactly as rolling to it', function (): void {
    $map = ['2026-03-02' => 80.0, '2026-03-05' => 45.5, '2026-04-20' => 120.0, '2026-05-01' => 60.0];
    $asOf = Carbon::parse('2026-04-26');
    $series = $this->load->rollDailySeries($map, Carbon::parse('2026-05-11'));

    expect($this->load->summaryFromDailyMap($map, runDaysOf($map), $asOf, loadSeries: $series))
        ->toBe($this->load->summaryFromDailyMap($map, runDaysOf($map), $asOf));
});

it('returns null from summaryFromDailyMap when the map is empty', function (): void {
    expect($this->load->summaryFromDailyMap([], [], Carbon::today()))->toBeNull();
});

it('ignores TRIMP from a not-yet-analyzed activity', function (): void {
    $user = User::factory()->create();
    seedUnanalyzedTrimpDay($user, 500.0, 1);

    expect($this->load->summary($user))->toBeNull();
});

it('rolls TRIMP into ATL/CTL/form with sane magnitudes', function (): void {
    $user = User::factory()->create();

    // Steady 80 TRIMP/day → ATL/CTL converge near 80.
    for ($i = 0; $i < 60; $i++) {
        seedTrimpDay($user, 80.0, 59 - $i);
    }

    $summary = $this->load->summary($user);

    expect($summary)->not->toBeNull()
        ->and($summary['atl_7d'])->toBeFloat()->toBeGreaterThan(70)->toBeLessThan(85)
        ->and($summary['ctl_42d'])->toBeFloat()->toBeGreaterThan(60)->toBeLessThan(85)
        ->and($summary['weekly_trimp'])->toBeFloat()->toEqualWithDelta(560.0, 5.0)
        ->and($summary['weekly_trimp'])->toBeGreaterThan(50)->toBeLessThan(2000);

});

it('converges CTL to a steady load instead of the too-low 49-day cold-start value', function (): void {
    $user = User::factory()->create();

    // 200 days of steady 80 TRIMP/day. A continuous EWMA converges CTL≈79.3.
    // The old 49-day warm-up window cold-started CTL near 55 (form over-reports
    // fatigue). summary() now reads full history, so it must report the
    // converged value, not the windowed one.
    for ($i = 0; $i < 200; $i++) {
        seedTrimpDay($user, 80.0, 199 - $i);
    }

    $summary = $this->load->summary($user);

    expect($summary['ctl_42d'])->toEqualWithDelta(79.3, 0.5)
        ->and($summary['ctl_42d'])->toBeGreaterThan(75.0);
});

it('computes a CTL independent of how many lead-in days the map carries', function (): void {
    // Continuous EWMA: CTL on a given day depends only on the full history up
    // to that day, not on which window a caller chose. A fully-warmed map and a
    // truncated 49-day map must NOT agree, and the warmed one matches the
    // hand-computed converged value.
    $asOf = Carbon::today();

    $fullMap = [];
    for ($i = 199; $i >= 0; $i--) {
        $fullMap[$asOf->copy()->subDays($i)->toDateString()] = 80.0;
    }
    $windowedMap = array_slice($fullMap, -49, null, true);

    $full = $this->load->summaryFromDailyMap($fullMap, runDaysOf($fullMap), $asOf);
    $windowed = $this->load->summaryFromDailyMap($windowedMap, runDaysOf($windowedMap), $asOf);

    expect($full['ctl_42d'])->toEqualWithDelta(79.3, 0.5)
        ->and($windowed['ctl_42d'])->toEqualWithDelta(55.1, 0.5)
        ->and($windowed['ctl_42d'])->toBeLessThan($full['ctl_42d'] - 15.0);
});

it('reports a steady weekly_trimp_range when weekly load never varies', function (): void {
    $user = User::factory()->create();
    // 8 weeks (56 days) of steady 80 TRIMP/day → every trailing week totals
    // 560, with zero day-to-day variance so monotony caps at 5.0 and strain
    // (560 * 5.0) is steady too — all three ranges collapse to a point.
    for ($i = 0; $i < 56; $i++) {
        seedTrimpDay($user, 80.0, 55 - $i);
    }

    $summary = $this->load->summary($user);

    expect($summary['weekly_trimp_range'])->toBe(['low' => 560.0, 'high' => 560.0])
        ->and($summary['monotony_range'])->toBe(['low' => 5.0, 'high' => 5.0])
        ->and($summary['strain_range'])->toBe(['low' => 2800.0, 'high' => 2800.0]);
});

it('reports the 25th-75th percentile of the trailing 8 weekly totals for TRIMP, monotony and strain alike', function (): void {
    $asOf = Carbon::today();

    // 8 non-overlapping weeks, weekly totals 140..1120 in steps of 140 (each
    // exactly divisible by 7, so the seeded daily figure has no float
    // drift). Every day within a week is identical, so monotony caps at 5.0
    // every week and strain is simply weekly * 5.0.
    $map = [];
    for ($week = 0; $week < 8; $week++) {
        $weekTotal = ($week + 1) * 140;
        for ($day = 0; $day < 7; $day++) {
            $date = $asOf->copy()->subDays($week * 7 + $day)->toDateString();
            $map[$date] = $weekTotal / 7;
        }
    }

    $summary = $this->load->summaryFromDailyMap($map, runDaysOf($map), $asOf);

    expect($summary['weekly_trimp_range'])->toBe(['low' => 390.0, 'high' => 880.0])
        ->and($summary['monotony_range'])->toBe(['low' => 5.0, 'high' => 5.0])
        ->and($summary['strain_range'])->toBe(['low' => 1930.0, 'high' => 4380.0]);
});

it('returns a null range for TRIMP, monotony and strain alike with fewer than two scorable weeks', function (): void {
    $user = User::factory()->create();
    // Day 0 is the only scored day. The other 7 non-overlapping weekly
    // windows each carry an HR-less run (in runDays but not dailyTrimp), so
    // weekStats reports them null rather than a false zero — only one
    // scorable week exists, short of the two a range needs. All three
    // figures come off the same filtered loop, so they're null together.
    seedTrimpDay($user, 80.0, 0);
    for ($i = 1; $i < 8; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => null,
            'start_date_local' => Carbon::today()->subDays($i * 7),
        ]);
    }

    $summary = $this->load->summary($user);

    expect($summary['weekly_trimp_range'])->toBeNull()
        ->and($summary['monotony_range'])->toBeNull()
        ->and($summary['strain_range'])->toBeNull();
});

it('zero-fills gap days between sparse activities', function (): void {
    // A 100-TRIMP day followed by 30 rest days: CTL decays across the gap but
    // never resets, so the rest day reduces fatigue (ATL) faster than fitness.
    $asOf = Carbon::today();
    $map = [
        $asOf->copy()->subDays(30)->toDateString() => 100.0,
        $asOf->toDateString() => 100.0,
    ];

    $summary = $this->load->summaryFromDailyMap($map, runDaysOf($map), $asOf);

    // ATL recovers toward the latest spike; CTL stays muted by the long gap.
    expect($summary['atl_7d'])->toBeGreaterThan($summary['ctl_42d'])
        ->and($summary['ctl_42d'])->toBeGreaterThan(0.0);
});

it('marks fresh when fitness exceeds fatigue (taper-week shape)', function (): void {
    $user = User::factory()->create();

    // Build CTL with 6w × 100 TRIMP/day, then 7-day taper at 30 TRIMP/day.
    for ($i = 0; $i < 42; $i++) {
        seedTrimpDay($user, 100.0, 49 - $i);
    }
    for ($i = 0; $i < 7; $i++) {
        seedTrimpDay($user, 30.0, 6 - $i);
    }

    $summary = $this->load->summary($user);

    expect($summary['form'])->toBeGreaterThan(0);
    expect($summary['form_status'])->toBeIn(['fresh', 'optimal']);

});

it('computes Foster monotony and strain over the week', function (): void {
    $user = User::factory()->create();

    // High-monotony: same 80 TRIMP every day for 7 days.
    for ($i = 0; $i < 7; $i++) {
        seedTrimpDay($user, 80.0, 6 - $i);
    }

    $summary = $this->load->summary($user);

    // Uniform daily TRIMP → sd≈0, so monotony hits its 5.0 cap exactly.
    expect($summary['monotony'])->toBe(5.0);
    expect($summary['strain'])->toBeFloat()->toBeGreaterThan(0);

});

it('reports zero weekly_trimp / monotony / strain on a fully rested current week', function (): void {
    $user = User::factory()->create();

    // History sits outside the last 7 days so week_total == 0.
    for ($i = 0; $i < 20; $i++) {
        seedTrimpDay($user, 60.0, 20 + $i);
    }

    $summary = $this->load->summary($user);

    expect($summary['weekly_trimp'])->toBe(0.0)
        ->and($summary['monotony'])->toBe(0.0)
        ->and($summary['strain'])->toBe(0.0);

});

it('sizes weekly_trimp/monotony/strain to the requested window, not a fixed 7 days', function (): void {
    $user = User::factory()->create();

    // Steady 60 TRIMP/day for the last 30 days.
    for ($i = 0; $i < 30; $i++) {
        seedTrimpDay($user, 60.0, 29 - $i);
    }

    $sevenDay = $this->load->summary($user, windowDays: 7);
    $thirtyDay = $this->load->summary($user, windowDays: 30);

    expect($sevenDay['weekly_trimp'])->toEqualWithDelta(420.0, 0.5)
        ->and($thirtyDay['weekly_trimp'])->toEqualWithDelta(1800.0, 0.5);
});

it('leaves ATL/CTL/form unchanged by the requested window, only the weekly figures move', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 60; $i++) {
        seedTrimpDay($user, 80.0, 59 - $i);
    }

    $sevenDay = $this->load->summary($user, windowDays: 7);
    $ninetyDay = $this->load->summary($user, windowDays: 90);

    expect($sevenDay['atl_7d'])->toBe($ninetyDay['atl_7d'])
        ->and($sevenDay['ctl_42d'])->toBe($ninetyDay['ctl_42d'])
        ->and($sevenDay['form'])->toBe($ninetyDay['form']);
});

it('only counts the requested user', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    seedTrimpDay($userA, 100.0, 0);

    expect($this->load->summary($userB))->toBeNull();

});

it('returns an empty ctlTrend for a user with no TRIMP-bearing activities', function (): void {
    $user = User::factory()->create();

    expect($this->load->ctlTrend($user))->toBe([]);
});

it('ctlTrend returns one entry per day for the last N days, matching the daily summary', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 100; $i++) {
        seedTrimpDay($user, 80.0, 99 - $i);
    }

    $trend = $this->load->ctlTrend($user, 90);

    expect($trend)->toHaveCount(90)
        ->and($trend[0]['date'])->toBe(Carbon::today()->subDays(89)->toDateString())
        ->and($trend[89]['date'])->toBe(Carbon::today()->toDateString());

    // The trend's last day must agree with the same EWMA roll the dashboard
    // summary reads — this is exposing already-computed numbers, not a
    // second, independent calculation.
    $summary = $this->load->summary($user);
    expect($trend[89]['atl'])->toEqualWithDelta($summary['atl_7d'], 0.05)
        ->and($trend[89]['ctl'])->toEqualWithDelta($summary['ctl_42d'], 0.05);
});

it('ctlTrend never returns more than the available history, even when asked for more days', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        seedTrimpDay($user, 80.0, 9 - $i);
    }

    expect($this->load->ctlTrend($user, 90))->toHaveCount(10);
});

it('ctlTrend stamps each day with formStatus, computed from that day\'s own atl/ctl', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 72; $i++) {
        seedTrimpDay($user, 80.0, 71 - $i);
    }

    $trend = $this->load->ctlTrend($user, 30);

    foreach ($trend as $point) {
        $form = round($point['ctl'] - $point['atl'], 1);
        expect($point['form_status'])->toBe($this->load->formStatus($form, $point['ctl']));
    }
});

it('tells a rest week, an unscored week and a scored week apart', function (): void {
    $user = User::factory()->create();

    // One history, three weeks that must never collapse into each other:
    // scored (HR present), rest (nobody ran), unscored (ran, no HR anywhere).
    seedTrimpDay($user, 120.0, 29);
    seedTrimpDay($user, 90.0, 31);
    seedTrimpDay($user, null, 1);
    seedTrimpDay($user, null, 3);

    $scored = $this->load->summary($user, Carbon::today()->subDays(28));
    $rest = $this->load->summary($user, Carbon::today()->subDays(14));
    $unscored = $this->load->summary($user, Carbon::today());

    expect($scored['weekly_trimp'])->toBeGreaterThan(0.0)
        ->and($scored['monotony'])->toBeGreaterThan(0.0)
        ->and($scored['strain'])->toBeGreaterThan(0.0);

    expect($rest['weekly_trimp'])->toBe(0.0)
        ->and($rest['monotony'])->toBe(0.0)
        ->and($rest['strain'])->toBe(0.0);

    expect($unscored['weekly_trimp'])->toBeNull()
        ->and($unscored['monotony'])->toBeNull()
        ->and($unscored['strain'])->toBeNull();
});

it('keeps ATL/CTL as numbers through an unscored stretch', function (): void {
    // The week columns go unknown, but fitness/fatigue are EWMAs of the real
    // scored history and stay reportable rather than nulling out with it.
    $user = User::factory()->create();
    seedTrimpDay($user, 120.0, 50);
    seedTrimpDay($user, null, 1);

    $summary = $this->load->summary($user, Carbon::today());

    expect($summary['weekly_trimp'])->toBeNull()
        ->and($summary['ctl_42d'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and($summary['atl_7d'])->toBeFloat()
        ->and($summary['form_status'])->toBeString();
});

it('returns an empty strainMonotonyTrend for a user with no TRIMP-bearing activities', function (): void {
    $user = User::factory()->create();

    expect($this->load->strainMonotonyTrend($user))->toBe([]);
});

it('strainMonotonyTrend returns one entry per requested day, agreeing with the daily summary', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 100; $i++) {
        seedTrimpDay($user, 80.0, 99 - $i);
    }

    $trend = $this->load->strainMonotonyTrend($user, 90);

    expect($trend)->toHaveCount(90)
        ->and($trend[0]['date'])->toBe(Carbon::today()->subDays(89)->toDateString())
        ->and($trend[89]['date'])->toBe(Carbon::today()->toDateString());

    // Last day's window must agree with summary()'s own weekStats() call —
    // this is exposing the same computation across a range, not a second one.
    $summary = $this->load->summary($user);
    expect($trend[89]['weekly_trimp'])->toBe($summary['weekly_trimp'])
        ->and($trend[89]['monotony'])->toBe($summary['monotony'])
        ->and($trend[89]['strain'])->toBe($summary['strain']);
});

it('strainMonotonyTrend caps monotony at 5.0 on a uniform-load week, same as summary()', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 7; $i++) {
        seedTrimpDay($user, 80.0, 6 - $i);
    }

    $trend = $this->load->strainMonotonyTrend($user, 7);

    expect($trend[6]['monotony'])->toBe(5.0)
        ->and($trend[6]['strain'])->toBeFloat()->toBeGreaterThan(0.0);
});

it('strainMonotonyTrend reports days before any history began as a rested zero, not a gap', function (): void {
    $user = User::factory()->create();

    // Only 5 days of real history, but a 30-day trend is asked for — the
    // 25 days before that history began are a legitimate "no load yet"
    // zero, not something to omit (unlike ctlTrend, which needs a start
    // date to roll the EWMA forward from and so can't extend before it).
    for ($i = 0; $i < 5; $i++) {
        seedTrimpDay($user, 80.0, 4 - $i);
    }

    $trend = $this->load->strainMonotonyTrend($user, 30);

    expect($trend)->toHaveCount(30)
        ->and($trend[0]['weekly_trimp'])->toBe(0.0)
        ->and($trend[0]['monotony'])->toBe(0.0)
        ->and($trend[0]['strain'])->toBe(0.0);
});

it('strainMonotonyTrend tells an unscored week apart from a rested one, same as summary()', function (): void {
    $user = User::factory()->create();
    seedTrimpDay($user, 120.0, 29);
    seedTrimpDay($user, null, 1);

    $trend = $this->load->strainMonotonyTrend($user, 1);

    expect($trend[0]['weekly_trimp'])->toBeNull()
        ->and($trend[0]['monotony'])->toBeNull()
        ->and($trend[0]['strain'])->toBeNull();
});

it('memoizes a null summary within the same instance instead of rescanning', function (): void {
    $user = User::factory()->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->load->summary($user))->toBeNull()
        ->and($this->load->summary($user))->toBeNull()
        ->and($this->load->summary($user))->toBeNull();

    expect($queries)->toBe(1);
});

it('memoizes a real summary within the same instance instead of rescanning', function (): void {
    $user = User::factory()->create();
    seedTrimpDay($user, 80.0, 0);

    $first = $this->load->summary($user);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $second = $this->load->summary($user);

    expect($queries)->toBe(0)
        ->and($second)->toBe($first);
});

it('clearSummaryCache forces the next summary() to recompute within the same scope', function (): void {
    $user = User::factory()->create();

    $this->instance(TrainingLoad::class, $this->load);
    expect($this->load->summary($user))->toBeNull();

    seedTrimpDay($user, 80.0, 0);
    TrainingLoad::clearSummaryCache($user);

    expect($this->load->summary($user))->not->toBeNull();
});

it('ignores a summary an earlier build cached under the unversioned key', function (): void {
    $user = User::factory()->create();
    seedTrimpDay($user, 80.0, 0);
    Cache::put("training-load:{$user->id}:".Carbon::today()->toDateString().':7', ['atl_7d' => 1.0]);

    expect($this->load->summary($user))->toHaveKey('weekly_trimp_range');
});

it('keeps distinct memo entries per user, date and window so they do not collide', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    seedTrimpDay($userA, 100.0, 0);
    seedTrimpDay($userA, 100.0, 20);
    seedTrimpDay($userB, 40.0, 0);

    $asOfA = Carbon::today();
    $asOfB = Carbon::today()->subDay();

    $summaryA = $this->load->summary($userA, $asOfA, windowDays: 7);
    $summaryB = $this->load->summary($userB, $asOfA, windowDays: 7);
    $summaryADifferentDate = $this->load->summary($userA, $asOfB, windowDays: 7);
    $summaryADifferentWindow = $this->load->summary($userA, $asOfA, windowDays: 30);

    expect($summaryA)->not->toBe($summaryB)
        ->and($summaryA)->not->toBe($summaryADifferentDate)
        ->and($summaryA['weekly_trimp'])->not->toBe($summaryADifferentWindow['weekly_trimp']);
});

/**
 * @return array<string, float>
 */
function steadyTrimpMap(Carbon $asOf, int $days, float $trimp, int $everyNthDay = 1): array
{
    $map = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        if ($i % $everyNthDay === 0) {
            $map[$asOf->copy()->subDays($i)->toDateString()] = $trimp;
        }
    }

    return $map;
}

it('reads form as unknown until 42 days of scored history follow the first scored day', function (): void {
    $asOf = Carbon::today();
    $warming = steadyTrimpMap($asOf, 42, 80.0);
    $warmed = steadyTrimpMap($asOf, 43, 80.0);

    $during = $this->load->summaryFromDailyMap($warming, runDaysOf($warming), $asOf);
    $after = $this->load->summaryFromDailyMap($warmed, runDaysOf($warmed), $asOf);

    expect($during['form_status'])->toBeNull()
        ->and($during['form_known_from'])->toBe($asOf->copy()->addDay()->toDateString())
        ->and($during['ctl_42d'])->toBeGreaterThan(0.0)
        ->and($after['form_status'])->not->toBeNull()
        ->and($after['form_known_from'])->toBe($asOf->toDateString());
});

it('stamps warm-up days in ctlTrend as unknown form', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 50; $i++) {
        seedTrimpDay($user, 80.0, 49 - $i);
    }

    $trend = $this->load->ctlTrend($user, 50);

    expect($trend[0]['form_status'])->toBeNull()
        ->and($trend[41]['form_status'])->toBeNull()
        ->and($trend[42]['form_status'])->not->toBeNull();
});

it('scales the form thresholds continuously with CTL, with no cliff at the old band edges', function (): void {
    expect($this->load->formStatus(-9.9, 10))->toBe('fatigued')
        ->and($this->load->formStatus(-10.1, 10))->toBe('overreaching')
        ->and($this->load->formStatus(-29.9, 30))->toBe('fatigued')
        ->and($this->load->formStatus(-30.1, 30))->toBe('overreaching')
        ->and($this->load->formStatus(-39.9, 60))->toBe('fatigued')
        ->and($this->load->formStatus(-40.1, 60))->toBe('overreaching')
        ->and($this->load->formStatus(20.1, 90))->toBe('fresh')
        ->and($this->load->formStatus(-11, 19.9))->toBe($this->load->formStatus(-11, 20.1))
        ->and($this->load->formStatus(-20, 49.9))->toBe($this->load->formStatus(-20, 50.1));
});

it('never reads fresher after more TRIMP on the last day', function (float $dailyTrimp, int $everyNthDay): void {
    $severity = ['fresh' => 0, 'optimal' => 1, 'fatigued' => 2, 'overreaching' => 3];
    $asOf = Carbon::today();
    $base = steadyTrimpMap($asOf->copy()->subDay(), 120, $dailyTrimp, $everyNthDay);

    $previous = -1;
    for ($extra = 0.0; $extra <= 600.0; $extra += 10.0) {
        $map = $base + [$asOf->toDateString() => $extra];
        $status = $this->load->summaryFromDailyMap($map, runDaysOf($map), $asOf)['form_status'];

        expect($severity[$status])->toBeGreaterThanOrEqual($previous);
        $previous = $severity[$status];
    }
})->with([
    'beginner, 2 runs a week' => [60.0, 3],
    'steady every other day' => [80.0, 2],
    'daily runner just under the old CTL-20 edge' => [19.0, 1],
    'daily runner' => [60.0, 1],
    'high-volume daily runner' => [150.0, 1],
]);

it('checks the personal range against an unrounded reference that leaves out the current window', function (): void {
    $asOf = Carbon::today();
    $steady = steadyTrimpMap($asOf, 9 * 7, 592.0 / 7);
    $spiking = array_merge($steady, steadyTrimpMap($asOf, 7, 1000.0 / 7));

    $steadySummary = $this->load->summaryFromDailyMap($steady, runDaysOf($steady), $asOf);
    $spikingSummary = $this->load->summaryFromDailyMap($spiking, runDaysOf($spiking), $asOf);

    expect($steadySummary['weekly_trimp_range'])->toBe(['low' => 590.0, 'high' => 590.0])
        ->and($steadySummary['weekly_trimp_reference']['high'])->toEqualWithDelta(592.0, 0.001)
        ->and($steadySummary['weekly_trimp'])->toBeLessThanOrEqual($steadySummary['weekly_trimp_reference']['high'] + 0.001)
        ->and($spikingSummary['weekly_trimp_reference']['high'])->toEqualWithDelta(592.0, 0.001);
});
