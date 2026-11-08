<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\ServedBy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use App\Events\EarlyPassStraddled;
use App\Listeners\RenarrateOnEarlyPassStraddled;
use Illuminate\Support\Facades\Event;
use Illuminate\Contracts\Queue\ShouldQueue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

it('re-requests the straddling row through AnalysisService with its own keys and invalidate', function (): void {
    $row = new Analysis()->forceFill([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 7,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->with(AnalysisType::BRIEFING_SUBJECT_TYPE, 7, AnalysisType::BriefingMascotVoice, '2026-05-18', null, true)
        ->andReturn($row);

    new RenarrateOnEarlyPassStraddled($service)->handle(new EarlyPassStraddled($row));
});

it('listens to EarlyPassStraddled synchronously, inside the after-commit callback that fired it', function (): void {
    Event::fake();

    Event::assertListening(EarlyPassStraddled::class, RenarrateOnEarlyPassStraddled::class);
    expect(new ReflectionClass(RenarrateOnEarlyPassStraddled::class)->implementsInterface(ShouldQueue::class))->toBeFalse();
});

it('re-requests a straddling row once instead of marking it early', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    Bus::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    // No unhydrated backlog exists right now, so isEarlyPassRow() alone would
    // say this row is not early — but its generation started while it was
    // (e.g. a slow Azure call spanning the moment the drain emptied). The
    // replay that would ever regenerate an early row has already run by now,
    // so this row asks for itself again instead of being left early.
    app(AnalysisRowState::class)->markDone($row, 'Run story.', ServedBy::Llm, startedEarly: true);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull()
        ->and(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Queued);
    // No notification on this pass either — it fires once, on the
    // re-requested pass that finally reads the complete history.
    Notification::assertNothingSent();
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
});
