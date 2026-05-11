<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Tests that compute against "today" all freeze the clock to the same
// reference instant; afterEach guarantees the freeze never leaks even
// when an earlier assertion blows up.
beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ── Edwards TRIMP ────────────────────────────────────────────────────────────

it('computes Edwards TRIMP as zone-weighted minute sum', function (): void {
    // 1*5 + 2*30 + 3*20 + 4*5 + 5*0 = 5 + 60 + 60 + 20 = 145
    $trimp = (new TrainingLoad())->edwardsTrimp([
        'Z1' => 5,
        'Z2' => 30,
        'Z3' => 20,
        'Z4' => 5,
        'Z5' => 0,
    ]);

    expect($trimp)->toEqualWithDelta(145.0, 0.01);
});

it('handles missing zones', function (): void {
    expect((new TrainingLoad())->edwardsTrimp(['Z2' => 60]))->toEqualWithDelta(120.0, 0.01);
});

it('a tempo (Z4-heavy) outscores an easy (Z2-heavy) of the same duration', function (): void {
    $easy = (new TrainingLoad())->edwardsTrimp(['Z2' => 60]);                              // 120
    $tempo = (new TrainingLoad())->edwardsTrimp(['Z1' => 5, 'Z2' => 10, 'Z4' => 40, 'Z5' => 5]); // 215

    expect($tempo)->toBeGreaterThan($easy * 1.5);
});

// ── Personalized form status ─────────────────────────────────────────────────

it('uses narrow thresholds for low-CTL beginners', function (): void {
    $load = new TrainingLoad();

    // CTL = 10 → threshold 5
    expect($load->formStatus(0, 10))->toBe('optimal')
        ->and($load->formStatus(-6, 10))->toBe('fatigued')
        ->and($load->formStatus(-15, 10))->toBe('overreaching');
});

it('uses moderate thresholds for mid-CTL runners', function (): void {
    $load = new TrainingLoad();

    // CTL = 40 → threshold 15
    expect($load->formStatus(0, 40))->toBe('optimal')
        ->and($load->formStatus(-20, 40))->toBe('fatigued')
        ->and($load->formStatus(-35, 40))->toBe('overreaching');
});

it('uses wide thresholds for veteran runners', function (): void {
    $load = new TrainingLoad();

    // CTL = 60 → threshold 20
    expect($load->formStatus(0, 60))->toBe('optimal')
        ->and($load->formStatus(-25, 60))->toBe('fatigued')
        ->and($load->formStatus(-50, 60))->toBe('overreaching')
        ->and($load->formStatus(25, 60))->toBe('fresh');
});

// ── Summary roll-up ──────────────────────────────────────────────────────────

it('returns null when the user has no TRIMP-bearing activities', function (): void {
    $user = User::factory()->create();
    expect((new TrainingLoad())->summary($user))->toBeNull();
});

it('rolls TRIMP into ATL/CTL/form with sane magnitudes', function (): void {
    $user = User::factory()->create();

    // Steady 80 TRIMP/day through today — ATL/CTL converge near 80
    for ($i = 0; $i < 60; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 80.0,
            'start_date_local' => Carbon::today()->copy()->subDays(59 - $i),
        ]);
    }

    $summary = (new TrainingLoad())->summary($user);

    expect($summary)->not->toBeNull()
        ->and($summary['atl_7d'])->toBeFloat()->toBeGreaterThan(70)->toBeLessThan(85)
        ->and($summary['ctl_42d'])->toBeFloat()->toBeGreaterThan(60)->toBeLessThan(85)
        // 7 days × 80 TRIMP = 560
        ->and($summary['weekly_trimp'])->toBeFloat()->toEqualWithDelta(560.0, 5.0)
        // Magnitude sanity: typical weekly TRIMP for a serious amateur is 200-1500
        ->and($summary['weekly_trimp'])->toBeGreaterThan(50)->toBeLessThan(2000);

});

it('marks fresh when fitness exceeds fatigue (taper-week shape)', function (): void {
    $user = User::factory()->create();

    // 6 weeks of 100 TRIMP/day to build CTL, then a 7-day taper at 30 TRIMP/day
    for ($i = 0; $i < 42; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 100.0,
            'start_date_local' => Carbon::today()->copy()->subDays(49 - $i),
        ]);
    }
    for ($i = 0; $i < 7; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 30.0,
            'start_date_local' => Carbon::today()->copy()->subDays(6 - $i),
        ]);
    }

    $summary = (new TrainingLoad())->summary($user);

    expect($summary['form'])->toBeGreaterThan(0);
    expect($summary['form_status'])->toBeIn(['fresh', 'optimal']);

});

it('computes Foster monotony and strain over the week', function (): void {
    $user = User::factory()->create();

    // High-monotony pattern: same 80 TRIMP every day for 7 days
    for ($i = 0; $i < 7; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 80.0,
            'start_date_local' => Carbon::today()->copy()->subDays(6 - $i),
        ]);
    }

    $summary = (new TrainingLoad())->summary($user);

    expect($summary['monotony'])->toBeFloat()->toBeGreaterThan(2.0); // very flat distribution
    expect($summary['strain'])->toBeFloat()->toBeGreaterThan(0);

});

it('reports zero weekly_trimp / monotony / strain on a fully rested current week', function (): void {
    $user = User::factory()->create();

    // History exists but only OUTSIDE the last 7 days (so week_total == 0).
    for ($i = 0; $i < 20; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 60.0,
            'start_date_local' => Carbon::today()->subDays(20 + $i), // 20-39 days ago
        ]);
    }

    $summary = (new TrainingLoad())->summary($user);

    expect($summary['weekly_trimp'])->toBe(0.0)
        ->and($summary['monotony'])->toBe(0.0)
        ->and($summary['strain'])->toBe(0.0);

});

it('only counts the requested user', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    ActivityDetail::factory()->for(Activity::factory()->for($userA)->create())->create([
        'trimp_edwards' => 100.0,
        'start_date_local' => Carbon::today(),
    ]);

    expect((new TrainingLoad())->summary($userB))->toBeNull();

});
