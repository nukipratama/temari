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
use App\Services\AI\Agent\Tools\MonthRunsTool;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $detail
 */
function monthRun(User $user, string $startedAt, array $detail = [], bool $analyzed = true): Activity
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

it('reads every run of the calendar month, its first day 00:00 to its last day 23:59, and nothing either side', function (): void {
    $user = User::factory()->create();
    monthRun($user, '2026-04-30 23:59:00');
    monthRun($user, '2026-05-01 00:00:00');
    monthRun($user, '2026-05-15 07:00:00');
    monthRun($user, '2026-05-31 23:59:00');
    monthRun($user, '2026-06-01 00:00:00');

    $tool = new MonthRunsTool($user, '2026-05', app(VerdictNarrator::class));
    $reading = $tool->handle([]);

    expect($tool->name())->toBe('get_month_runs')
        ->and($tool->description())->toContain("Every run of the month you're telling, first day to last in order")
        ->and($reading['month_start'])->toBe('2026-05-01')
        ->and($reading['month_ending'])->toBe('2026-05-31')
        ->and(array_column(arrayOf($reading['runs']), 'date'))->toBe(['2026-05-01', '2026-05-15', '2026-05-31']);
});

it('reads a shorter month to its own last day', function (): void {
    $user = User::factory()->create();
    monthRun($user, '2026-02-28 23:59:00');
    monthRun($user, '2026-03-01 00:00:00');

    $reading = new MonthRunsTool($user, '2026-02', app(VerdictNarrator::class))->handle([]);

    expect($reading['month_ending'])->toBe('2026-02-28')
        ->and(array_column(arrayOf($reading['runs']), 'date'))->toBe(['2026-02-28']);
});

it('reads an empty month as no runs, and leaves out another athlete and an unanalysed activity', function (): void {
    $user = User::factory()->create();
    monthRun(User::factory()->create(), '2026-05-10 07:00:00');
    monthRun($user, '2026-05-11 07:00:00', analyzed: false);

    expect(new MonthRunsTool($user, '2026-05', app(VerdictNarrator::class))->handle([]))->toBe([
        'month_start' => '2026-05-01',
        'month_ending' => '2026-05-31',
        'runs' => [],
    ]);
});

it('carries the run in quotable forms with the plan session and the verdict of the day', function (): void {
    $user = User::factory()->create();
    $activity = monthRun($user, '2026-05-12 07:00:00', [
        'name' => 'Morning Run',
        'distance' => 10000.0,
        'moving_time' => 3000,
        'elapsed_time' => 3060,
        'average_heartrate' => 151.6,
        'trimp_edwards' => 30.0,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id, 'activity_id' => $activity->id, 'kind' => StoryLine::KIND_POST_RUN,
        'mood' => Mood::Blazing, 'speech' => null,
    ]);
    Analysis::factory()->done('held the pace to the end.')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-05-12', 'session_type' => SessionType::Tempo, 'status' => PlannedSessionStatus::Done,
    ]);

    [$run] = arrayOf(new MonthRunsTool($user, '2026-05', app(VerdictNarrator::class))->handle([])['runs']);

    expect($run)->toMatchArray([
        'day' => 'Tuesday',
        'date' => '2026-05-12',
        'name' => 'Morning Run',
        'km' => 10.0,
        'moving_time' => '50:00',
        'pace_formatted' => '5:06',
        'avg_hr' => 152,
        'mood' => 'blazing',
        'verdict' => 'held the pace to the end.',
        'planned' => ['session_type' => 'tempo', 'grade' => 'done'],
    ]);
});
