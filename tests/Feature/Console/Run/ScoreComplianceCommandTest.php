<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Actions\Run\Plan\ResolveTrainingPreferenceAction;
use App\Console\SchedulerChain;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Jobs\Run\ReconcilePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\TrainingPreference;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

/** @param list<int> $failingUserIds */
function failScoringFor(array $failingUserIds): void
{
    $realPreference = app(ResolveTrainingPreferenceAction::class);
    $preference = Mockery::mock(ResolveTrainingPreferenceAction::class);
    $preference->shouldReceive('__invoke')->andReturnUsing(
        static function (int $userId) use ($failingUserIds, $realPreference): ?TrainingPreference {
            if (in_array($userId, $failingUserIds, true)) {
                throw new LogicException('test scoring failure');
            }

            return $realPreference($userId);
        },
    );
    app()->instance(ResolveTrainingPreferenceAction::class, $preference);
}

it('continues scoring later athletes when one athlete throws', function (): void {
    Carbon::setTestNow('2026-08-10');
    $failing = User::factory()->create();
    $later = User::factory()->create();
    PlannedSession::factory()->for($failing)->rest()->create(['date' => Carbon::yesterday()]);
    PlannedSession::factory()->for($later)->rest()->create(['date' => Carbon::yesterday()]);

    failScoringFor([$failing->id]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $failing->id)->firstOrFail()->status)->toBe(PlannedSessionStatus::Planned)
        ->and(PlannedSession::query()->where('user_id', $later->id)->firstOrFail()->status)->toBe(PlannedSessionStatus::Done);

    Carbon::setTestNow();
});

it('lets the Monday scheduler open the regenerate gate after a partial scoring failure and alerts once', function (): void {
    Bus::fake();
    Config::set('services.telegram.bot_token', 'test-bot-token');
    Carbon::setTestNow('2026-08-10');
    $failing = User::factory()->create(['name' => 'Private Failed Athlete']);
    $later = User::factory()->create(['name' => 'Private Later Athlete']);
    PlannedSession::factory()->for($failing)->rest()->create(['date' => Carbon::yesterday()]);
    PlannedSession::factory()->for($later)->rest()->create(['date' => Carbon::yesterday()]);
    failScoringFor([$failing->id]);

    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    expect(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeFalse();

    $this->artisan('plan:score-compliance')->assertSuccessful();

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse()
        ->and(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeFalse();
    $scoreCompliance = collect(app(Schedule::class)->events())->first(
        static fn (Event $event): bool => str_contains((string) $event->command, 'plan:score-compliance'),
    );
    expect($scoreCompliance)->not->toBeNull();
    $scoreCompliance->finish(app(), 0);
    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeTrue()
        ->and(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeTrue();
    $regenerate = collect(app(Schedule::class)->events())->first(
        static fn (Event $event): bool => str_contains((string) $event->command, 'plan:regenerate'),
    );
    expect($regenerate)->not->toBeNull()
        ->and($regenerate->filtersPass(app()))->toBeTrue();
    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
    Bus::assertDispatched(SendMaintainerAlertJob::class, static fn (SendMaintainerAlertJob $job): bool =>
        str_contains($job->message, 'plan:score-compliance')
        && str_contains($job->message, '1 athlete')
        && ! str_contains($job->message, 'Private Failed Athlete')
        && ! str_contains($job->message, 'Private Later Athlete'));

    Carbon::setTestNow();
});

it('keeps the Monday regenerate gate closed when every athlete fails scoring', function (): void {
    Bus::fake();
    Config::set('services.telegram.bot_token', 'test-bot-token');
    Carbon::setTestNow('2026-08-10');
    $first = User::factory()->create();
    $second = User::factory()->create();
    PlannedSession::factory()->for($first)->rest()->create(['date' => Carbon::yesterday()]);
    PlannedSession::factory()->for($second)->rest()->create(['date' => Carbon::yesterday()]);
    failScoringFor([$first->id, $second->id]);

    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    $this->artisan('plan:score-compliance')->assertFailed();

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse()
        ->and(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeFalse();
    $regenerate = collect(app(Schedule::class)->events())->first(
        static fn (Event $event): bool => str_contains((string) $event->command, 'plan:regenerate'),
    );
    expect($regenerate)->not->toBeNull()
        ->and($regenerate->filtersPass(app()))->toBeFalse();
    $scoreCompliance = collect(app(Schedule::class)->events())->first(
        static fn (Event $event): bool => str_contains((string) $event->command, 'plan:score-compliance'),
    );
    expect($scoreCompliance)->not->toBeNull();
    $scoreCompliance->finish(app(), 1);
    expect($regenerate->filtersPass(app()))->toBeFalse();
    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
    Bus::assertDispatched(SendMaintainerAlertJob::class, static fn (SendMaintainerAlertJob $job): bool =>
        str_contains($job->message, 'plan:score-compliance')
        && str_contains($job->message, 'Scheduler failed to run'));

    Carbon::setTestNow();
});

it('does not open the Monday chain after a scoped manual scoring command', function (): void {
    Carbon::setTestNow('2026-08-10');
    $selected = User::factory()->create();
    $other = User::factory()->create();
    PlannedSession::factory()->for($selected)->rest()->create(['date' => Carbon::yesterday()]);
    PlannedSession::factory()->for($other)->rest()->create(['date' => Carbon::yesterday()]);

    $this->artisan('plan:score-compliance', ['--user' => $selected->id])->assertSuccessful();
    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse();

    $this->artisan('plan:score-compliance', ['--limit' => 1])->assertSuccessful();
    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse();

    Carbon::setTestNow();
});

function seedPastWeekOfSessions(User $user, Carbon $weekStart, PlanPhase $phase = PlanPhase::Base): void
{
    for ($i = 0; $i < 7; $i++) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($i),
            'phase' => $phase,
            'session_type' => SessionType::Easy,
        ]);
    }
}

it('scores a past week, crediting the day that met its target and marking the rest missed', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $weekStart = Carbon::today()->subWeeks(2)->startOfWeek(Carbon::MONDAY);
    seedPastWeekOfSessions($user, $weekStart);

    // A stable anchor run, well outside the trailing baseline window's overlap
    // with the test week, pins long_run_km so Monday's own logged distance
    // below can't retroactively change its own target.
    $anchorActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($anchorActivity)->create([
        'start_date_local' => Carbon::today()->subDays(20)->setTime(7, 0),
        'distance' => 20_000,
    ]);

    $baselineData = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $mondayTargetKm = SegmentGenerator::coreKmFor(
        SessionType::Easy,
        true,
        $baselineData['long_run_km'],
        1.0,
        $baselineData['long_run_cap_km'],
    );
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $weekStart->copy()->setTime(7, 0),
        'distance' => $mondayTargetKm * 1000,
    ]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 7 planned session(s) across 1 user(s).')
        ->assertSuccessful();

    $rows = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();

    expect($rows[0]->status->value)->toBe('done')
        ->and($rows[0]->compliance_score)->not->toBeNull()
        ->and($rows[1]->status->value)->toBe('missed')
        ->and($rows[1]->compliance_score)->toBe(0);

    Carbon::setTestNow();
});

it('schedules plan reconciliation after settling past sessions', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->subDays(2)]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    Bus::assertDispatched(ReconcilePlanJob::class, fn (ReconcilePlanJob $job): bool => $job->userId === $user->id);

    Carbon::setTestNow();
});

it('uses the latest scored date when a backlog spans irrelevant and relevant weeks', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-07-20']);
    PlannedSession::factory()->for($user)->create(['date' => Carbon::yesterday()]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    expect($user->fresh()->plan_reconciliation_pending_from->toDateString())
        ->toBe(Carbon::yesterday()->toDateString());

    Carbon::setTestNow();
});

it('limits to a single user via --user', function (): void {
    Carbon::setTestNow('2026-08-12');
    $a = User::factory()->create();
    $b = User::factory()->create();
    $pastDate = Carbon::today()->subDays(3);

    PlannedSession::factory()->for($a)->rest()->create(['date' => $pastDate]);
    PlannedSession::factory()->for($b)->rest()->create(['date' => $pastDate]);

    $this->artisan("plan:score-compliance --user={$a->id}")
        ->expectsOutputToContain('Scored 1 planned session(s) across 1 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->first()->status->value)->toBe('done')
        ->and(PlannedSession::query()->where('user_id', $b->id)->first()->status->value)->toBe('planned');

    Carbon::setTestNow();
});

it('marks a skipped day as Skip regardless of logged distance', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create([
        'date' => $pastDate,
        'skipped' => true,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $pastDate->copy()->setTime(7, 0),
        'distance' => 10_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('skip')
        ->and($row->compliance_score)->toBeNull()
        ->and($row->ran_anyway)->toBeFalse();

    Carbon::setTestNow();
});

it('leaves future planned sessions untouched', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->addDay()]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 0 planned session(s) across 0 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $user->id)->first()->status->value)->toBe('planned');

    Carbon::setTestNow();
});

it('does nothing when there are no past-due Planned rows', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->scored()->create(['date' => Carbon::today()->subDays(3)]);

    $this->artisan('plan:score-compliance')
        ->expectsOutputToContain('Scored 0 planned session(s) across 0 user(s).')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('marks a past rest day Done and flags ran_anyway when logged despite being rest', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->rest()->create(['date' => $pastDate]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $pastDate->copy()->setTime(7, 0),
        'distance' => 5_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('done')
        ->and($row->compliance_score)->toBeNull()
        ->and($row->ran_anyway)->toBeTrue();

    Carbon::setTestNow();
});

/**
 * The case the advisory clamp exists to fix: the card told the athlete to rest,
 * they rested, and grading them against the session it replaced scored them 0%
 * and called it `missed` for complying. The clamp cannot be recomputed here —
 * the ceiling that produced it counted that day's own runs — so
 * RestClampRecorder wrote it down at the time.
 */
it('excuses a day the readiness clamp had downgraded to a full rest', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $pastDate = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create([
        'date' => $pastDate,
        'session_type' => SessionType::Long,
        'rest_clamped_at' => $pastDate->copy()->setTime(6, 0),
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->first();
    expect($row->status->value)->toBe('skip')
        ->and($row->compliance_score)->toBeNull();

    Carbon::setTestNow();
});

it('still marks an unclamped long day missed when nothing was run', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDays(2),
        'session_type' => SessionType::Long,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $user->id)->first()->status->value)->toBe('missed');

    Carbon::setTestNow();
});

it('grades race day against the race distance even once the goal behind it has been retired', function (): void {
    Carbon::setTestNow('2026-08-12');
    $user = User::factory()->create();
    $raceDate = Carbon::today()->subDay();

    // plan:close-finished-races runs at 00:02, before this command — by the
    // time race day is graded the RaceGoal is already retired, so the row's
    // own race_distance_m is the only thing left that knows the distance.
    PlannedSession::factory()->for($user)->create([
        'date' => $raceDate,
        'phase' => PlanPhase::Taper,
        'session_type' => SessionType::Race,
        'race_distance_m' => 10_000,
    ]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $raceDate->copy()->setTime(7, 0),
        'distance' => 10_000,
    ]);

    $this->artisan('plan:score-compliance')->assertSuccessful();

    $row = PlannedSession::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->status)->toBe(PlannedSessionStatus::Done)
        ->and($row->compliance_score)->toBe(100)
        // The failure this replaces: a 0 km target scored the race "rest day,
        // ran anyway" instead of grading it.
        ->and($row->ran_anyway)->toBeFalse();
});
