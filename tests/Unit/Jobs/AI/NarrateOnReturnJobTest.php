<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Jobs\AI\NarrateOnReturnJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ServedBy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    // Wednesday: the latest closed week ends 2026-06-14 and the latest closed month is 2026-05.
    Carbon::setTestNow('2026-06-17 09:00:00');
    config()->set('ai.backfill_max_age_days', 84);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function returningAthlete(): User
{
    return User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
}

function runLeftPendingWhileAway(User $user, string $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create(['analyzed_at' => Carbon::parse($startedAt)]);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startedAt),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    $service = app(AnalysisService::class);
    $service->requestActivityGroupDeferred($activity);
    $service->requestDeferred(RunCard::class, $card->id, AnalysisType::CardFlavor);

    return $activity;
}

function weeklyRecapLeftPending(User $user, string $weekEnding): WeeklySnapshot
{
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'runs' => 3]);
    app(AnalysisService::class)->requestDeferred(WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap);

    return $snapshot;
}

function monthlyRecapLeftPending(User $user, string $month): void
{
    app(AnalysisService::class)->requestDeferred(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, $month);
}

function returnRowStatus(string $subjectType, int $subjectId, AnalysisType $type, ?string $discriminator = null): AnalysisStatus
{
    return Analysis::query()->forSubject($subjectType, $subjectId, $type, $discriminator)->firstOrFail()->status;
}

function returnRowReason(string $subjectType, int $subjectId, AnalysisType $type, ?string $discriminator = null): ?AnalysisOrigin
{
    return Analysis::query()->forSubject($subjectType, $subjectId, $type, $discriminator)->firstOrFail()->rule_based_reason;
}

function narrateOnReturn(User $user): void
{
    app()->call([new NarrateOnReturnJob($user->id), 'handle']);
}

function dispatchedOnReturn(string $jobClass): Closure
{
    return fn (AnalyzeBaseJob $job): bool => $job::class === $jobClass && $job->origin === AnalysisOrigin::Return;
}

it('narrates the runs of the last seven days and fills older ones rule-based', function (): void {
    $athlete = returningAthlete();
    $recent = runLeftPendingWhileAway($athlete, '2026-06-12 06:30:00');
    $older = runLeftPendingWhileAway($athlete, '2026-06-02 06:30:00');

    narrateOnReturn($athlete);

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $recent->id
        && $job->origin === AnalysisOrigin::Return);
    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class, dispatchedOnReturn(AnalyzeCardFlavorJob::class));
    Bus::assertDispatchedTimes(AnalyzeCardFlavorJob::class, 1);

    $olderCardId = nonNull($older->runCard)->id;
    $olderRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $older->id)->get();
    expect($olderRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done
        && $row->served_by === ServedBy::RuleBased
        && $row->rule_based_reason === AnalysisOrigin::Return))->toBeTrue()
        ->and(returnRowStatus(RunCard::class, $olderCardId, AnalysisType::CardFlavor))->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->forSubject(RunCard::class, $olderCardId, AnalysisType::CardFlavor)->firstOrFail()->rule_based_reason)
        ->toBe(AnalysisOrigin::Return);
});

it('narrates the latest closed week and month and fills older missed recaps rule-based', function (): void {
    $athlete = returningAthlete();
    $latestWeek = weeklyRecapLeftPending($athlete, '2026-06-14');
    $olderWeek = weeklyRecapLeftPending($athlete, '2026-06-07');
    $openWeek = weeklyRecapLeftPending($athlete, '2026-06-21');
    monthlyRecapLeftPending($athlete, '2026-06');
    monthlyRecapLeftPending($athlete, '2026-05');
    monthlyRecapLeftPending($athlete, '2026-04');

    narrateOnReturn($athlete);

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class, dispatchedOnReturn(AnalyzeWeeklyRecapJob::class));
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    Bus::assertDispatched(AnalyzeMonthlyRecapJob::class, dispatchedOnReturn(AnalyzeMonthlyRecapJob::class));
    Bus::assertDispatchedTimes(AnalyzeMonthlyRecapJob::class, 1);

    $monthly = AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE;
    expect(returnRowStatus(WeeklySnapshot::class, $latestWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Queued)
        ->and(returnRowStatus(WeeklySnapshot::class, $olderWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Done)
        ->and(returnRowStatus(WeeklySnapshot::class, $openWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Pending)
        ->and(returnRowStatus($monthly, $athlete->id, AnalysisType::MonthlyRecap, '2026-05'))->toBe(AnalysisStatus::Queued)
        ->and(returnRowStatus($monthly, $athlete->id, AnalysisType::MonthlyRecap, '2026-04'))->toBe(AnalysisStatus::Done)
        ->and(returnRowStatus($monthly, $athlete->id, AnalysisType::MonthlyRecap, '2026-06'))->toBe(AnalysisStatus::Pending)
        ->and(returnRowReason(WeeklySnapshot::class, $olderWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisOrigin::Return)
        ->and(returnRowReason($monthly, $athlete->id, AnalysisType::MonthlyRecap, '2026-04'))->toBe(AnalysisOrigin::Return);
});

function olderWeekStillHydrating(User $user): Activity
{
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-06-04 06:30:00')]);

    return $activity;
}

it('leaves an older missed week Pending while its runs still hydrate', function (): void {
    $athlete = returningAthlete();
    olderWeekStillHydrating($athlete);
    $olderWeek = weeklyRecapLeftPending($athlete, '2026-06-07');

    narrateOnReturn($athlete);

    expect(returnRowStatus(WeeklySnapshot::class, $olderWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Pending);
});

it('fills an older missed week rule-based once its runs have hydrated', function (): void {
    $athlete = returningAthlete();
    olderWeekStillHydrating($athlete)->update(['ingest_state' => IngestState::Detailed]);
    $olderWeek = weeklyRecapLeftPending($athlete, '2026-06-07');

    narrateOnReturn($athlete);

    expect(returnRowStatus(WeeklySnapshot::class, $olderWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Done)
        ->and(returnRowReason(WeeklySnapshot::class, $olderWeek->id, AnalysisType::WeeklyRecap))->toBe(AnalysisOrigin::Return);
});

it('fills the latest closed month rule-based when it closed before the athlete connected', function (): void {
    $athlete = returningAthlete();
    StravaConnection::factory()->for($athlete)->create(['created_at' => Carbon::parse('2026-06-02 08:00:00')]);
    monthlyRecapLeftPending($athlete, '2026-05');

    narrateOnReturn($athlete);

    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);
    expect(returnRowStatus(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $athlete->id, AnalysisType::MonthlyRecap, '2026-05'))->toBe(AnalysisStatus::Done)
        ->and(returnRowReason(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $athlete->id, AnalysisType::MonthlyRecap, '2026-05'))->toBe(AnalysisOrigin::Return);
});

it('leaves a failed older recap failed rather than hiding the fault behind filler', function (): void {
    $athlete = returningAthlete();
    $failed = weeklyRecapLeftPending($athlete, '2026-06-07');
    Analysis::query()->forSubject(WeeklySnapshot::class, $failed->id, AnalysisType::WeeklyRecap)->update(['status' => AnalysisStatus::Failed]);

    narrateOnReturn($athlete);

    expect(returnRowStatus(WeeklySnapshot::class, $failed->id, AnalysisType::WeeklyRecap))->toBe(AnalysisStatus::Failed);
});

it('bills nothing twice when the return job runs again', function (): void {
    $athlete = returningAthlete();
    runLeftPendingWhileAway($athlete, '2026-06-12 06:30:00');
    runLeftPendingWhileAway($athlete, '2026-06-02 06:30:00');
    weeklyRecapLeftPending($athlete, '2026-06-14');
    monthlyRecapLeftPending($athlete, '2026-05');

    narrateOnReturn($athlete);
    narrateOnReturn($athlete);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    Bus::assertDispatchedTimes(AnalyzeCardFlavorJob::class, 1);
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    Bus::assertDispatchedTimes(AnalyzeMonthlyRecapJob::class, 1);
});

it('does nothing for an athlete who no longer exists', function (): void {
    app()->call([new NarrateOnReturnJob(999_999), 'handle']);

    Bus::assertNothingDispatched();
});
