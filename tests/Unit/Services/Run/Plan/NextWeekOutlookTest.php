<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\NextWeekOutlook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const OUTLOOK_MONDAY = '2026-11-02';

const OUTLOOK_DROP = ['date' => '2026-11-12', 'time_trial' => false, 'session_type' => 'tempo'];

function outlookHotDay(User $user, string $date, int $overCapSec, SessionType $type = SessionType::Easy): void
{
    PlannedSession::factory()->for($user)->create(['date' => $date, 'phase' => PlanPhase::Build, 'session_type' => $type]);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'moving_time' => 3000,
        'stream_summary' => ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overCapSec],
    ]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00'));
});

it('is null while no easy day has run hot, and for the demo athlete', function (bool $demo, int $overCapSec): void {
    $user = User::factory()->create(['is_demo' => $demo]);
    outlookHotDay($user, '2026-11-03', $overCapSec);

    expect(app(NextWeekOutlook::class)->for($user, Carbon::today()))->toBeNull();
})->with([
    'under the cap' => [false, 300],
    'demo' => [true, 800],
]);

it('names one hot day and the session next week would lose to one more', function (): void {
    $user = User::factory()->create();
    outlookHotDay($user, '2026-11-03', 800);
    PlanAdaptation::factory()->for($user)->create(['week_start' => OUTLOOK_MONDAY, 'next_week_drop' => OUTLOOK_DROP]);

    expect(app(NextWeekOutlook::class)->for($user, Carbon::today()))->toBe([
        'state' => NextWeekOutlook::ONE_MORE,
        'egregious' => false,
        'hot_days' => [['date' => '2026-11-03', 'session_type' => 'easy', 'over_cap_minutes' => 13]],
        'drop' => OUTLOOK_DROP,
        'blocker' => null,
    ]);
});

it('sets next week once two easy days run hot, or one far over, naming them in date order', function (): void {
    $user = User::factory()->create();
    outlookHotDay($user, '2026-11-07', 900, SessionType::Long);
    outlookHotDay($user, '2026-11-03', 800);

    $outlook = nonNull(app(NextWeekOutlook::class)->for($user, Carbon::today()));

    expect($outlook['state'])->toBe(NextWeekOutlook::SET)
        ->and($outlook['egregious'])->toBeFalse()
        ->and(array_column($outlook['hot_days'], 'date'))->toBe(['2026-11-03', '2026-11-07'])
        ->and($outlook['drop'])->toBeNull();

    $far = User::factory()->create();
    outlookHotDay($far, '2026-11-03', 2_400);

    expect(app(NextWeekOutlook::class)->for($far, Carbon::today()))->toMatchArray(['state' => NextWeekOutlook::SET, 'egregious' => true]);
});

it('names the race rule that would keep next week as the plan set it', function (): void {
    $user = User::factory()->create();
    outlookHotDay($user, '2026-11-03', 800);
    PlanAdaptation::factory()->for($user)->create(['week_start' => OUTLOOK_MONDAY, 'next_week_drop' => OUTLOOK_DROP]);
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-11-15']);

    expect(nonNull(app(NextWeekOutlook::class)->for($user, Carbon::today()))['blocker'])->toBe('race week');
});

it('words the hot days, the dropped session and its bring-back state', function (): void {
    $tuesday = ['date' => '2026-11-03', 'session_type' => 'easy', 'over_cap_minutes' => 9];
    $saturday = ['date' => '2026-11-07', 'session_type' => 'long', 'over_cap_minutes' => 14];

    expect(NextWeekOutlook::hotDayLine($saturday))->toBe("saturday's long run went 14 min over your heart-rate cap")
        ->and(NextWeekOutlook::hotDaysLine([$saturday]))->toBe("saturday's long run (14 min) went over your heart-rate cap")
        ->and(NextWeekOutlook::hotDaysLine([$tuesday, $tuesday, $saturday]))->toBe("tuesday's easy run (9 min), tuesday's easy run (9 min) and saturday's long run (14 min) went over your heart-rate cap")
        ->and(NextWeekOutlook::dropLabel(OUTLOOK_DROP))->toBe("thursday's tempo")
        ->and(NextWeekOutlook::dropLabel([...OUTLOOK_DROP, 'time_trial' => true]))->toBe("thursday's time trial")
        ->and(NextWeekOutlook::bringBackLine(null))->toBe('you can bring it back from the plan on the day.')
        ->and(NextWeekOutlook::bringBackLine(null, would: true))->toBe('you could bring it back from the plan on the day.')
        ->and(NextWeekOutlook::bringBackLine('race week'))->toBe("you can't bring it back: it's race week, and the weeks closest to a race stay as the plan set them.");
});
