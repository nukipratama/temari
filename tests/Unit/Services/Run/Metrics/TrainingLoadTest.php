<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Freeze "today" so Carbon::today() math is stable; afterEach prevents leak on failure.
beforeEach(function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $this->load = new TrainingLoad();
});
afterEach(fn () => Carbon::setTestNow());

function seedTrimpDay(User $user, float $trimp, int $daysAgo): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'start_date_local' => Carbon::today()->subDays($daysAgo),
    ]);
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

it('returns null when the user has no TRIMP-bearing activities', function (): void {
    $user = User::factory()->create();
    expect($this->load->summary($user))->toBeNull();
});

it('returns null from summaryFromDailyMap when the map is empty', function (): void {
    expect($this->load->summaryFromDailyMap([], Carbon::today()))->toBeNull();
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

    $full = $this->load->summaryFromDailyMap($fullMap, $asOf);
    $windowed = $this->load->summaryFromDailyMap($windowedMap, $asOf);

    expect($full['ctl_42d'])->toEqualWithDelta(79.3, 0.5)
        ->and($windowed['ctl_42d'])->toEqualWithDelta(55.1, 0.5)
        ->and($windowed['ctl_42d'])->toBeLessThan($full['ctl_42d'] - 15.0);
});

it('zero-fills gap days between sparse activities', function (): void {
    // A 100-TRIMP day followed by 30 rest days: CTL decays across the gap but
    // never resets, so the rest day reduces fatigue (ATL) faster than fitness.
    $asOf = Carbon::today();
    $map = [
        $asOf->copy()->subDays(30)->toDateString() => 100.0,
        $asOf->toDateString() => 100.0,
    ];

    $summary = $this->load->summaryFromDailyMap($map, $asOf);

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

    expect($summary['monotony'])->toBeFloat()->toBeGreaterThan(2.0);
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

it('only counts the requested user', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    seedTrimpDay($userA, 100.0, 0);

    expect($this->load->summary($userB))->toBeNull();

});
