<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Support\Arr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('recomputes stored streams and rebuilds the plan without external work', function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subWeek(),
        'stream_summary' => null,
    ]);
    ActivityStream::factory()->for($activity)->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->subWeek()]);
    $analysis = Analysis::factory()->done('Keep this narration')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => Carbon::today()->subWeek()->toDateString(),
    ]);

    $result = app(PlanRecalibrationService::class)->recalibrate($user);

    expect($result['activities'])->toBe(1)
        ->and($activity->detail->fresh()->stream_summary)->not->toBeNull()
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '>=', Carbon::today())->exists())->toBeTrue()
        ->and($analysis->fresh()->content)->toBe('Keep this narration')
        ->and($analysis->fresh()->stale_at)->toBeNull()
        ->and($user->fresh()->plan_recalibration_started_at)->not->toBeNull()
        ->and($user->fresh()->plan_recalibration_completed_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('holds both locks while its work runs inside the recalibration transaction', function (): void {
    $user = User::factory()->create();

    $held = app(PlanRecalibrationService::class)->exclusively($user, false, 3600, function (User $user): array {
        $plan = Cache::lock("plan-reconciliation:{$user->id}", 3600);
        $training = Cache::lock(RecalibrateTrainingHistoryJob::overlapLockKey($user->id), 3600);

        return ['plan' => ! $plan->get(), 'training' => ! $training->get(), 'transaction' => DB::transactionLevel() > 0];
    });

    expect($held)->toBe(['plan' => true, 'training' => true, 'transaction' => true])
        ->and($user->fresh()->plan_recalibration_completed_at)->not->toBeNull();
});

it('does not auto-reconcile max HR while rebuilding under the current profile', function (): void {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $profile = RunnerProfile::factory()->for($user)->create(['max_hr' => 180]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subWeek(),
        'max_heartrate' => 195,
    ]);
    ActivityStream::factory()->for($activity)->create();

    app(PlanRecalibrationService::class)->recalibrate($user);

    expect($profile->fresh()->max_hr)->toBe(180);
});

it('recomputes stream summaries in chronological order across bounded batches', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $createdIds = [];

    foreach (range(1, 30) as $daysAgo) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->subDays($daysAgo),
        ]);
        ActivityStream::factory()->for($activity)->create();
        $createdIds[] = $activity->id;
    }

    $processedIds = [];
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('recomputeSummary')
        ->times(30)
        ->andReturnUsing(function (Activity $activity, bool $rebuildAggregates, bool $reconcileMaxHeartRate) use (&$processedIds): void {
            $processedIds[] = $activity->id;
        });
    app()->instance(ActivityPipeline::class, $pipeline);

    $result = app(PlanRecalibrationService::class)->recalibrate($user);

    expect($result['activities'])->toBe(30)
        ->and($processedIds)->toBe(array_reverse($createdIds));
});

it('rolls back every write in dry-run mode', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    RecalibrateTrainingHistoryJob::markDirty($user->id);

    app(PlanRecalibrationService::class)->recalibrate($user, dryRun: true);

    expect($user->fresh()->plan_recalibration_started_at)->toBeNull()
        ->and(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id)))->toBeTrue();

    Queue::assertNothingPushed();
});

it('keeps past prescriptions, grades and their narration through an ordinary recalibration', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subWeek()->setTime(6, 0),
        'stream_summary' => null,
    ]);
    ActivityStream::factory()->for($activity)->create();
    $past = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subWeek(),
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 17,
        'prescribed_pace_band' => PaceBand::Threshold,
        'prescribed_pace_sec_per_km' => 301,
        'prescription_reason' => 'shown at the time',
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 97,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['basis' => 'pace', 'advice_history' => 'shown'],
    ]);
    $before = Arr::only($past->fresh()->getAttributes(), ['prescribed_hard_minutes', 'prescribed_pace_band', 'prescribed_pace_sec_per_km', 'prescription_reason', 'status', 'compliance_score', 'intent_verdict', 'intent_evidence']);
    $narration = Analysis::factory()->done('Shown that day')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => Carbon::today()->subWeek()->toDateString(),
    ]);

    $result = app(PlanRecalibrationService::class)->recalibrate($user);

    expect($result['activities'])->toBe(1)
        ->and($activity->detail->fresh()->stream_summary)->not->toBeNull()
        ->and(Arr::only($past->fresh()->getAttributes(), array_keys($before)))->toBe($before)
        ->and($narration->fresh()->stale_at)->toBeNull();
});
