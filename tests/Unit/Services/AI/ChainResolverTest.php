<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Every chain query caps at the latest fully-closed period, so these tests are
// wall-clock dependent. Pinned to a Wednesday whose last-closed week ends
// 2026-06-14 and whose last-closed month is 2026-05.
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-17');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function chainResolver(): ChainResolver
{
    return new ChainResolver();
}

function chainWeek(User $user, string $weekEnding, ?AnalysisStatus $status, int $runs = 3, int $attempts = 0): WeeklySnapshot
{
    $snapshot = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => $weekEnding,
        'runs' => $runs,
    ]);

    if ($status !== null) {
        Analysis::factory()->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snapshot->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            'discriminator' => null,
            'status' => $status,
            'attempts' => $attempts,
            'content' => $status === AnalysisStatus::Done ? 'narrated' : null,
        ]);
    }

    return $snapshot;
}

function chainMonth(User $user, string $month, AnalysisStatus $status, int $attempts = 0): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => $month,
        'status' => $status,
        'attempts' => $attempts,
        'content' => $status === AnalysisStatus::Done ? 'narrated' : null,
    ]);
}

function chainRun(User $user, string $startDate, AnalysisType $type, AnalysisStatus $status): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startDate)]);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => $type,
        'discriminator' => null,
        'status' => $status,
        'content' => $status === AnalysisStatus::Done ? 'narrated' : null,
    ]);

    return $activity;
}

function chainDoneRow(AnalysisStatus $status = AnalysisStatus::Done): Analysis
{
    return new Analysis(['status' => $status]);
}

it('treats only the latest closed running week as the weekly chain head', function (): void {
    $user = User::factory()->create();
    $mid = chainWeek($user, '2026-05-31', AnalysisStatus::Done);
    $head = chainWeek($user, '2026-06-14', AnalysisStatus::Done);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $head->id, null, chainDoneRow()))->toBeTrue()
        ->and(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $mid->id, null, chainDoneRow()))->toBeFalse();
});

it('never treats the still-open current week as the weekly chain head', function (): void {
    $user = User::factory()->create();
    $closed = chainWeek($user, '2026-06-14', AnalysisStatus::Done);
    $open = chainWeek($user, '2026-06-21', AnalysisStatus::Done);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $open->id, null, chainDoneRow()))->toBeFalse()
        ->and(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $closed->id, null, chainDoneRow()))->toBeTrue();
});

it('never treats a week with no runs as the weekly chain head', function (): void {
    $user = User::factory()->create();
    $withRuns = chainWeek($user, '2026-06-07', AnalysisStatus::Done);
    $empty = chainWeek($user, '2026-06-14', AnalysisStatus::Done, runs: 0);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $empty->id, null, chainDoneRow()))->toBeFalse()
        ->and(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $withRuns->id, null, chainDoneRow()))->toBeTrue();
});

it('never treats another user\'s chain head as this user\'s head', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirHead = chainWeek($other, '2026-06-14', AnalysisStatus::Done);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $theirHead->id, null, chainDoneRow()))->toBeFalse();
});

it('refuses a head regenerate for any row that is not Done', function (AnalysisStatus $status): void {
    $user = User::factory()->create();
    $head = chainWeek($user, '2026-06-14', $status);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $head->id, null, chainDoneRow($status)))->toBeFalse();
})->with([
    'pending' => AnalysisStatus::Pending,
    'queued' => AnalysisStatus::Queued,
    'processing' => AnalysisStatus::Processing,
    'failed' => AnalysisStatus::Failed,
]);

it('refuses a head regenerate when there is no existing row at all', function (): void {
    $user = User::factory()->create();
    $head = chainWeek($user, '2026-06-14', null);

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::WeeklyRecap, $head->id, null, null))->toBeFalse();
});

it('treats only the latest closed month as the monthly chain head', function (): void {
    $user = User::factory()->create();
    chainMonth($user, '2026-04', AnalysisStatus::Done);
    chainMonth($user, '2026-05', AnalysisStatus::Done);
    chainMonth($user, '2026-06', AnalysisStatus::Done);

    $resolver = chainResolver();

    expect($resolver->isHeadRegenerate($user, AnalysisType::MonthlyRecap, $user->id, '2026-05', chainDoneRow()))->toBeTrue()
        ->and($resolver->isHeadRegenerate($user, AnalysisType::MonthlyRecap, $user->id, '2026-04', chainDoneRow()))->toBeFalse()
        ->and($resolver->isHeadRegenerate($user, AnalysisType::MonthlyRecap, $user->id, '2026-06', chainDoneRow()))->toBeFalse()
        ->and($resolver->isHeadRegenerate($user, AnalysisType::MonthlyRecap, $user->id, null, chainDoneRow()))->toBeFalse();
});

it('treats only the latest run as the per-activity chain head', function (AnalysisType $type): void {
    $user = User::factory()->create();
    $older = chainRun($user, '2026-06-01 06:00:00', $type, AnalysisStatus::Done);
    $latest = chainRun($user, '2026-06-10 06:00:00', $type, AnalysisStatus::Done);

    expect(chainResolver()->isHeadRegenerate($user, $type, $latest->id, null, chainDoneRow()))->toBeTrue()
        ->and(chainResolver()->isHeadRegenerate($user, $type, $older->id, null, chainDoneRow()))->toBeFalse();
})->with([
    'post_run_speech' => AnalysisType::PostRunSpeech,
    'run_insight_technical' => AnalysisType::RunInsightTechnical,
    'run_insight_splits' => AnalysisType::RunInsightSplits,
    'run_insight_zones' => AnalysisType::RunInsightZones,
]);

it('never reports a head regenerate for an unchained type', function (): void {
    $user = User::factory()->create();

    expect(chainResolver()->isHeadRegenerate($user, AnalysisType::CardFlavor, 1, null, chainDoneRow()))->toBeFalse()
        ->and(chainResolver()->isHeadRegenerate($user, AnalysisType::BriefingSuggestion, $user->id, '2026-06-17', chainDoneRow()))->toBeFalse();
});

it('resumes the earliest unfilled weekly link and skips the Done ones', function (): void {
    $user = User::factory()->create();
    chainWeek($user, '2026-05-24', AnalysisStatus::Done);
    $earliestUnfilled = chainWeek($user, '2026-05-31', AnalysisStatus::Pending);
    chainWeek($user, '2026-06-07', AnalysisStatus::Failed);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::WeeklyRecap)?->subjectId)->toBe($earliestUnfilled->id);
});

it('never resumes a weekly link for the still-open week or a week with no runs', function (): void {
    $user = User::factory()->create();
    chainWeek($user, '2026-06-07', AnalysisStatus::Done);
    chainWeek($user, '2026-06-14', AnalysisStatus::Pending, runs: 0);
    chainWeek($user, '2026-06-21', AnalysisStatus::Pending);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::WeeklyRecap))->toBeNull();
});

it('resumes a weekly week that has no analysis row at all', function (): void {
    $user = User::factory()->create();
    $staged = chainWeek($user, '2026-05-31', null);
    chainWeek($user, '2026-06-07', AnalysisStatus::Done);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::WeeklyRecap)?->subjectId)->toBe($staged->id);
});

it('never resumes another user\'s weekly link', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    chainWeek($other, '2026-05-03', AnalysisStatus::Pending);
    $mine = chainWeek($user, '2026-06-07', AnalysisStatus::Pending);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::WeeklyRecap)?->subjectId)->toBe($mine->id);
});

it('resumes the earliest unfilled monthly link, never the still-open month', function (): void {
    $user = User::factory()->create();
    chainMonth($user, '2026-03', AnalysisStatus::Done);
    chainMonth($user, '2026-04', AnalysisStatus::Failed);
    chainMonth($user, '2026-06', AnalysisStatus::Pending);

    $link = chainResolver()->earliestUnfilledLink($user, AnalysisType::MonthlyRecap);

    expect($link?->subjectId)->toBe($user->id)
        ->and($link?->discriminator)->toBe('2026-04');
});

it('returns no monthly link when every closed month is Done', function (): void {
    $user = User::factory()->create();
    chainMonth($user, '2026-04', AnalysisStatus::Done);
    chainMonth($user, '2026-05', AnalysisStatus::Done);
    chainMonth($user, '2026-06', AnalysisStatus::Pending);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::MonthlyRecap))->toBeNull();
});

it('resumes the earliest run whose clicked-type row is not Done', function (): void {
    $user = User::factory()->create();
    $oldest = chainRun($user, '2026-06-01 06:00:00', AnalysisType::PostRunSpeech, AnalysisStatus::Done);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $oldest->id,
        'analysis_type' => AnalysisType::RunInsightSplits,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    chainRun($user, '2026-06-10 06:00:00', AnalysisType::PostRunSpeech, AnalysisStatus::Pending);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::RunInsightSplits)?->subjectId)->toBe($oldest->id);
});

it('returns no per-activity link when every run of that type is Done', function (): void {
    $user = User::factory()->create();
    chainRun($user, '2026-06-01 06:00:00', AnalysisType::PostRunSpeech, AnalysisStatus::Done);
    chainRun($user, '2026-06-10 06:00:00', AnalysisType::PostRunSpeech, AnalysisStatus::Done);

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::PostRunSpeech))->toBeNull();
});

it('returns no link for an unchained type', function (): void {
    $user = User::factory()->create();

    expect(chainResolver()->earliestUnfilledLink($user, AnalysisType::CardFlavor))->toBeNull()
        ->and(chainResolver()->earliestUnfilledLink($user, AnalysisType::PrContext))->toBeNull();
});

it('still offers a dead-lettered weekly link to a manual resume, unlike the sweep', function (): void {
    $user = User::factory()->create();
    $burned = chainWeek($user, '2026-06-07', AnalysisStatus::Failed, attempts: Analysis::MAX_SELF_HEAL_ATTEMPTS);

    $resolver = chainResolver();

    expect($resolver->earliestUnfilledLink($user, AnalysisType::WeeklyRecap)?->subjectId)->toBe($burned->id)
        ->and($resolver->stalledWeeklyLinkPerUser())->toHaveCount(0);
});

it('sweeps one earliest stalled weekly link per user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mineEarliest = chainWeek($user, '2026-05-31', AnalysisStatus::Failed);
    chainWeek($user, '2026-06-07', AnalysisStatus::Pending);
    $theirs = chainWeek($other, '2026-06-14', AnalysisStatus::Pending);

    $links = chainResolver()->stalledWeeklyLinkPerUser();

    expect($links->pluck('subjectId')->all())->toEqualCanonicalizing([$mineEarliest->id, $theirs->id]);
});

it('never sweeps a weekly link that a demo user, an open week, an empty week or a settled status owns', function (): void {
    $demo = User::factory()->demo()->create();
    chainWeek($demo, '2026-06-07', AnalysisStatus::Pending);

    $user = User::factory()->create();
    chainWeek($user, '2026-06-21', AnalysisStatus::Pending);
    chainWeek($user, '2026-06-14', AnalysisStatus::Pending, runs: 0);
    chainWeek($user, '2026-06-07', AnalysisStatus::Done);
    chainWeek($user, '2026-05-31', AnalysisStatus::Queued);

    expect(chainResolver()->stalledWeeklyLinkPerUser())->toHaveCount(0);
});

it('sweeps one earliest stalled monthly link per user, bounded by the retry budget', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    chainMonth($user, '2026-03', AnalysisStatus::Failed, attempts: Analysis::MAX_SELF_HEAL_ATTEMPTS);
    chainMonth($user, '2026-04', AnalysisStatus::Pending);
    chainMonth($user, '2026-05', AnalysisStatus::Pending);
    chainMonth($user, '2026-06', AnalysisStatus::Pending);
    chainMonth($other, '2026-05', AnalysisStatus::Failed);

    $links = chainResolver()->stalledMonthlyLinkPerUser();

    expect($links)->toHaveCount(2)
        ->and($links->firstWhere('subjectId', $user->id)?->discriminator)->toBe('2026-04')
        ->and($links->firstWhere('subjectId', $other->id)?->discriminator)->toBe('2026-05');
});

it('never sweeps a monthly link for the still-open month', function (): void {
    $user = User::factory()->create();
    chainMonth($user, '2026-06', AnalysisStatus::Pending);

    expect(chainResolver()->stalledMonthlyLinkPerUser())->toHaveCount(0);
});
