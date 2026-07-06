<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Freeze "today" so Carbon::today() math is stable; afterEach prevents leak on failure.
beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('returns hibernating when the user has never run', function (): void {
    $user = User::factory()->create();

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
});

it('returns hibernating when the last run is 12 days ago', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(12),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
});

it('does not hibernate when the last run was today', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(6, 20),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->not->toBe(Vibe::HIBERNATING);
});

it('does not hibernate when the last run was yesterday', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDay()->setTime(6, 20),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->not->toBe(Vibe::HIBERNATING);
});

it('does not hibernate when the last run is 9 days ago (just under the threshold)', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(9),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->not->toBe(Vibe::HIBERNATING);
});

it('hibernates when the last run is exactly 10 days ago (threshold boundary)', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(10),
        'trimp_edwards' => 80.0,
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
});

it('does not hibernate when the analysis as-of date predates the latest run', function (): void {
    // A daily-greeting row freezes its discriminator (as-of date) at creation;
    // a later regeneration can run with an as-of that is *before* the newest run.
    // The signed diff would go negative, so the age must clamp to 0, not flip
    // the runner into hibernating or report a negative age.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(6, 20),
        'trimp_edwards' => 80.0,
    ]);

    $asOfBeforeRun = Carbon::today()->subDays(3);

    expect(app(Vibe::class)->current($user, $asOfBeforeRun))->not->toBe(Vibe::HIBERNATING);
});

/** 80 days of steady 50 TRIMP/day → CTL catches ATL, form ≈ -7 (within optimal). */
function userWithOptimalForm(): User
{
    $user = User::factory()->create();
    for ($i = 0; $i < 80; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays(79 - $i),
        ]);
    }

    return $user;
}

it('returns pumped on a recent PR with non-negative form', function (): void {
    $user = userWithOptimalForm();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(3),
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::PUMPED);
});

it('still counts a PR set exactly 14 days ago as recent', function (): void {
    $user = userWithOptimalForm();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(14),
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::PUMPED);
});

it('no longer counts a PR set 15 days ago as recent', function (): void {
    $user = userWithOptimalForm();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(15),
    ]);

    expect(app(Vibe::class)->current($user))->not->toBe(Vibe::PUMPED);
});

it('averages decoupling_pct across recent runs to drive the vibe', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 80; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays(79 - $i),
            // Mix rows with/without decoupling_pct to exercise the isset guard.
            'stream_summary' => $i % 2 === 0
                ? ['decoupling_pct' => -2.0]
                : ['time_in_zone_min' => ['Z2' => 30]],
        ]);
    }

    expect(app(Vibe::class)->current($user))->toBe(Vibe::BOUNCY);
});

it('exposes display labels in Indonesian', function (): void {
    expect(Vibe::label(Vibe::BOUNCY))->toBe('Lincah')
        ->and(Vibe::label(Vibe::COOKED))->toBe('Gosong')
        ->and(Vibe::label(Vibe::HIBERNATING))->toBe('Hibernasi')
        ->and(Vibe::emoji(Vibe::PUMPED))->toBe('💥');
});
