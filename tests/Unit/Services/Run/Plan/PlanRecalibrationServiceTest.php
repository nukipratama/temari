<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('recomputes stored streams, rebuilds the plan, and marks old plan narration stale without external work', function (): void {
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
        ->and($analysis->fresh()->stale_at)->not->toBeNull()
        ->and($user->fresh()->plan_recalibration_started_at)->not->toBeNull()
        ->and($user->fresh()->plan_recalibration_completed_at)->not->toBeNull();

    Queue::assertNothingPushed();
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
