<?php

declare(strict_types=1);

use App\Enums\Mood;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\Agent\Tools\WeekRunsTool;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-06-03 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $detail
 */
function weekRun(User $user, string $startedAt, array $detail = [], bool $analyzed = true): Activity
{
    $factory = Activity::factory()->for($user);
    $activity = ($analyzed ? $factory : $factory->stub())->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startedAt),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
        ...$detail,
    ]);

    return $activity;
}

function weekRunsSnapshot(User $user): WeeklySnapshot
{
    return WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31']);
}

/** @return list<array<string, mixed>> */
function weekRunsOf(WeeklySnapshot $snapshot): array
{
    /** @var list<array<string, mixed>> $runs */
    $runs = new WeekRunsTool($snapshot, app(VerdictNarrator::class))->handle([])['runs'];

    return $runs;
}

it('reads every run from the snapshot Monday to its Sunday 23:59, oldest first, and nothing either side', function (): void {
    $user = User::factory()->create();
    weekRun($user, '2026-05-24 23:59:00');
    weekRun($user, '2026-05-25 00:00:00');
    weekRun($user, '2026-05-31 23:59:00');
    weekRun($user, '2026-06-01 00:00:00');

    $tool = new WeekRunsTool(weekRunsSnapshot($user), app(VerdictNarrator::class));
    $reading = $tool->handle([]);

    expect($reading['week_start'])->toBe('2026-05-25')
        ->and($reading['week_ending'])->toBe('2026-05-31')
        ->and(array_column(arrayOf($reading['runs']), 'date'))->toBe(['2026-05-25', '2026-05-31'])
        ->and(array_column(arrayOf($reading['runs']), 'day'))->toBe(['Monday', 'Sunday']);
});

it('reads an empty week as no runs', function (): void {
    $user = User::factory()->create();

    expect(new WeekRunsTool(weekRunsSnapshot($user), app(VerdictNarrator::class))->handle([]))->toBe([
        'week_start' => '2026-05-25',
        'week_ending' => '2026-05-31',
        'runs' => [],
    ]);
});

it('leaves out an activity the snapshot does not count and another athlete runs', function (): void {
    $user = User::factory()->create();
    weekRun($user, '2026-05-26 07:00:00');
    weekRun($user, '2026-05-27 07:00:00', analyzed: false);
    weekRun(User::factory()->create(), '2026-05-27 07:00:00');

    expect(array_column(weekRunsOf(weekRunsSnapshot($user)), 'date'))->toBe(['2026-05-26']);
});

it('reads the run itself in quotable forms', function (): void {
    $user = User::factory()->create();
    weekRun($user, '2026-05-27 06:30:00', [
        'name' => 'Morning Run',
        'distance' => 10000.0,
        'moving_time' => 3000,
        'elapsed_time' => 3060,
        'average_heartrate' => 151.6,
    ]);
    weekRun($user, '2026-05-28 06:30:00', ['average_heartrate' => null, 'moving_time' => 4000]);

    [$run, $noHr] = weekRunsOf(weekRunsSnapshot($user));

    expect($run)->toMatchArray([
        'day' => 'Wednesday',
        'date' => '2026-05-27',
        'name' => 'Morning Run',
        'km' => 10.0,
        'moving_time' => '50:00',
        'pace_formatted' => '5:06',
        'avg_hr' => 152,
    ])
        ->and($noHr['avg_hr'])->toBeNull()
        ->and($noHr['moving_time'])->toBe('1:06:40');
});

it('carries the mood, intensity and one-line verdict of a narrated run, and nulls for one still unnarrated', function (): void {
    $user = User::factory()->create();
    $narrated = weekRun($user, '2026-05-26 07:00:00', ['trimp_edwards' => 30.0]);
    $pending = weekRun($user, '2026-05-27 07:00:00');
    foreach ([[$narrated, Mood::Blazing], [$pending, Mood::Easy]] as [$activity, $mood]) {
        StoryLine::query()->create([
            'user_id' => $user->id, 'activity_id' => $activity->id, 'kind' => StoryLine::KIND_POST_RUN,
            'mood' => $mood, 'speech' => null,
        ]);
    }
    Analysis::factory()->done('negative split, and it felt easy.')->create([
        'subject_type' => Activity::class,
        'subject_id' => $narrated->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    weekRun($user, '2026-05-28 07:00:00');

    [$first, $second, $summaryOnly] = weekRunsOf(weekRunsSnapshot($user));

    expect($first)->toMatchArray(['mood' => 'blazing', 'intensity' => 'easy', 'verdict' => 'negative split, and it felt easy.'])
        ->and($second)->toMatchArray(['mood' => 'easy', 'verdict' => null])
        ->and($summaryOnly)->toMatchArray(['mood' => null, 'intensity' => null, 'verdict' => null]);
});

it('matches each run to the plan session of its day with the type it was asked to be and its grade', function (): void {
    $user = User::factory()->create();
    weekRun($user, '2026-05-26 07:00:00');
    weekRun($user, '2026-05-28 07:00:00');
    weekRun($user, '2026-05-30 07:00:00');
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-26', 'session_type' => SessionType::Tempo, 'status' => PlannedSessionStatus::Partial,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-28', 'session_type' => SessionType::Interval, 'status' => PlannedSessionStatus::Done,
        'intent_evidence' => ['effective_type' => 'easy'],
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-29', 'session_type' => SessionType::Long, 'status' => PlannedSessionStatus::Missed,
    ]);

    expect(array_column(weekRunsOf(weekRunsSnapshot($user)), 'planned'))->toBe([
        ['session_type' => 'tempo', 'grade' => 'partial'],
        ['session_type' => 'easy', 'grade' => 'done'],
        null,
    ]);
});
