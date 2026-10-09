<?php

declare(strict_types=1);

use App\Actions\Run\DeleteIngestedRunAction;
use App\Enums\PerformanceEvidenceKind;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PerformanceEvidence;
use App\Models\PersonalRecord;
use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\WeeklyAggregator;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

function deletableRun(User $user, CarbonInterface $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => $startedAt,
        'trimp_edwards' => 80,
    ]);

    return $activity;
}

it('deletes the run and heals its week, records, narration and dirty marks', function (): void {
    $user = User::factory()->create();
    $day = now()->startOfWeek()->subWeek()->addDay()->setTime(6, 0);
    $doomed = deletableRun($user, $day);
    $survivor = deletableRun($user, $day->copy()->addDay());
    app(WeeklyAggregator::class)->rebuildFor($user);
    $weekEnding = $day->copy()->endOfWeek(CarbonInterface::SUNDAY)->toDateString();
    PersonalRecord::factory()->for($user)->forActivity($doomed)->create(['category' => '5km']);
    $card = RunCard::factory()->create(['activity_id' => $doomed->id]);
    Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => $doomed->id, 'analysis_type' => AnalysisType::PostRunSpeech]);
    Analysis::factory()->create(['subject_type' => Activity::class, 'subject_id' => $survivor->id, 'analysis_type' => AnalysisType::PostRunSpeech]);
    Analysis::factory()->create(['subject_type' => RunCard::class, 'subject_id' => $card->id, 'analysis_type' => AnalysisType::CardFlavor]);

    app(DeleteIngestedRunAction::class)($doomed);

    $user->refresh();
    expect(Activity::query()->withStubs()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', $weekEnding)->value('runs'))->toBe(1)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->whereNull('activity_id')->exists())->toBeFalse()
        ->and(Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $doomed->id)->exists())->toBeFalse()
        ->and(Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $survivor->id)->exists())->toBeTrue()
        ->and(Analysis::query()->where('subject_type', RunCard::class)->where('subject_id', $card->id)->exists())->toBeFalse()
        ->and($user->trend_snapshots_pending_from?->toDateString())->toBe($day->toDateString())
        ->and($user->plan_reconciliation_pending_from?->toDateString())->toBe($day->toDateString());
});

it('drops the emptied weeks when the deleted run was the only one', function (): void {
    $user = User::factory()->create();
    $sole = deletableRun($user, now()->startOfWeek()->subWeek()->addDay());
    app(WeeklyAggregator::class)->rebuildFor($user);
    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeTrue();

    app(DeleteIngestedRunAction::class)($sole);

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('retracts the run\'s time-trial evidence', function (): void {
    $user = User::factory()->create();
    $doomed = deletableRun($user, now()->startOfWeek()->subWeek()->addDay()->setTime(6, 0));
    $test = runEvidence($user, $doomed, PerformanceEvidenceKind::Test);

    app(DeleteIngestedRunAction::class)($doomed);

    expect(PerformanceEvidence::query()->whereKey($test->id)->exists())->toBeFalse();
});

it('keeps a race result the athlete confirmed from the run', function (): void {
    $user = User::factory()->create();
    $doomed = deletableRun($user, now()->startOfWeek()->subWeek()->addDay()->setTime(6, 0));
    $race = runEvidence($user, $doomed, PerformanceEvidenceKind::Race, RaceGoal::factory()->for($user)->completed()->create()->id);

    app(DeleteIngestedRunAction::class)($doomed);

    expect($race->fresh()->activity_id)->toBeNull();
});
