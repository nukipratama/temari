<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\Feedback;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\InAppChannel;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CostCeilingLedger;
use App\Services\Ops\MaintainerAlerter;
use App\Services\AI\NarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\ServedBy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use App\Services\AI\AnalysisService;
use App\Events\EarlyPassStraddled;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

function analysisRowState(): AnalysisRowState
{
    return app(AnalysisRowState::class);
}

it('markDone records content and generated_at', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::User);

    analysisRowState()->markDone($row, 'final narrative', ServedBy::Llm);

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('final narrative')
        ->and($fresh->generated_at)->not->toBeNull()
        ->and($fresh->cooldownRemaining())->toBeGreaterThan(0);
});

it('markDone stores a content fingerprint when given, and leaves it null otherwise', function (): void {
    $withFingerprint = Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    $without = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    analysisRowState()->markDone($withFingerprint, 'story', ServedBy::Llm, fingerprint: 'abc123');
    analysisRowState()->markDone($without, 'headline', ServedBy::Llm);

    expect(nonNull($withFingerprint->fresh())->content_fingerprint)->toBe('abc123')
        ->and(nonNull($without->fresh())->content_fingerprint)->toBeNull();
});

it('markDone uses supplied generatedAt when given', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $past = Carbon::now()->subHours(2);
    analysisRowState()->markDone($row, 'demo content', ServedBy::Llm, $past);

    $fresh = nonNull($row->fresh());
    expect(nonNull($fresh->generated_at)->toIso8601String())->toBe($past->toIso8601String());
});

it('markFailed records error message without clearing prior content', function (): void {
    $row = Analysis::factory()->done('prior content')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    analysisRowState()->markFailed($row, 'Azure 500');

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure 500')
        ->and($fresh->content)->toBe('prior content');
});

it('markFailed alerts maintainers exactly at the dead-letter crossing', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisRowState::class);
    $rowState = app(AnalysisRowState::class);

    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    $alerter->shouldReceive('deadLettered')->once();

    $rowState->markFailed($row, 'Azure 500');
});

it('markFailed does not alert while a Failed row is still under the retry budget', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisRowState::class);
    $rowState = app(AnalysisRowState::class);

    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 1,
    ]);

    $alerter->shouldNotReceive('deadLettered');

    $rowState->markFailed($row, 'Azure 500');
});

it('increments attempts atomically from a stale row model', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 0,
    ]);

    $stale = $row->fresh();
    analysisRowState()->markProcessing($row);
    expect(analysisRowState()->markProcessing(nonNull($stale)))->toBeFalse()
        ->and(analysisRowState()->markProcessing(nonNull($stale), allowProcessing: true))->toBeTrue();

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Processing)
        ->and($fresh->attempts)->toBe(2);
});

it('keeps the attempt when a processing row is reset for recovery', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 0,
    ]);

    analysisRowState()->markProcessing($row);
    analysisRowState()->revertToPending($row);

    expect(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Pending)
        ->and($row->attempts)->toBe(1);
});

it('upsertGroupRows leaves pre-existing rows unflagged and flags only the newly inserted ones', function (): void {
    $activity = Activity::factory()->create();
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $rows = analysisRowState()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->wasRecentlyCreated)->toBeFalse()
        ->and(nonNull($rows->get(AnalysisType::RunInsight->value))->wasRecentlyCreated)->toBeTrue()
        ->and($rows->filter(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toHaveCount(1);
});

it('upsertGroupRows flags nothing when every row already exists', function (): void {
    $activity = Activity::factory()->create();
    app(AnalysisService::class)->requestActivityGroup($activity);

    $rows = analysisRowState()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows->contains(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeFalse();
});

it('marks only rows inserted by this caller as recently created when an insert races', function (): void {
    $activity = Activity::factory()->create();
    $insertedByRacer = false;

    DB::listen(function (QueryExecuted $query) use ($activity, &$insertedByRacer): void {
        if ($insertedByRacer || ! str_contains(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'ai_analyses')) {
            return;
        }

        $insertedByRacer = true;
        Analysis::factory()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::RunInsight,
            'discriminator' => null,
        ]);
    });

    $rows = analysisRowState()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($insertedByRacer)->toBeTrue()
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->wasRecentlyCreated)->toBeTrue()
        ->and(nonNull($rows->get(AnalysisType::RunInsight->value))->wasRecentlyCreated)->toBeFalse();
});

it('upsertGroupRows flags the rows it created, which stay Pending until the whole group is claimed', function (): void {
    Carbon::setTestNow('2026-05-18 07:10:59');
    $activity = Activity::factory()->create();

    $rows = analysisRowState()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->exists && $row->id > 0))->toBeTrue()
        ->and($rows->keys()->all())->toBe(
            array_map(fn (AnalysisType $type): string => $type->value, AnalyzeActivityJob::groupedTypes()),
        );

    $row = nonNull($rows->get(AnalysisType::PostRunSpeech->value));

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->queued_at)->toBeNull()
        ->and($row->generation_token)->toBeNull()
        ->and($row->created_at->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->updated_at->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->attempts)->toBe(0);

    Carbon::setTestNow();
});

it('inserted group rows rest Pending with no queued_at while dispatch is suppressed', function (): void {
    $activity = Activity::factory()->create();

    app(NarrationGate::class)->withoutDispatching(function () use ($activity): void {
        $rows = analysisRowState()->upsertGroupRows(
            Activity::class,
            $activity->id,
            null,
            AnalyzeActivityJob::groupedTypes(),
        );

        expect($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Pending))->toBeTrue()
            ->and($rows->every(fn (Analysis $row): bool => $row->queued_at === null))->toBeTrue();
    });
});

it('does not let a finishing job overwrite a newer group generation', function (): void {
    $activity = Activity::factory()->create();
    app(AnalysisService::class)->requestActivityGroup($activity);
    $stale = Analysis::query()->where('subject_id', $activity->id)->firstOrFail();
    $oldToken = $stale->generation_token;

    Analysis::query()->where('subject_id', $activity->id)->update([
        'status' => AnalysisStatus::Done->value,
        'content' => 'previous generation',
    ]);

    app(AnalysisService::class)->requestActivityGroup($activity, invalidate: true);

    $fresh = Analysis::query()->whereKey($stale->id)->firstOrFail();
    expect($oldToken)->not->toBeNull()
        ->and($fresh->generation_token)->not->toBe($oldToken)
        ->and($fresh->status)->toBe(AnalysisStatus::Queued)
        ->and(analysisRowState()->markDone(
            $stale,
            'late previous generation',
            ServedBy::Llm,
            generationToken: $oldToken,
        ))->toBeFalse()
        ->and(nonNull($fresh->fresh())->content)->toBe('previous generation');
});

it('markDone fans out a notification for a notifiable, wired type', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->analysis->id === $row->id,
    );
});

it('markDone sends no notification for a narration requested on the athlete return', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Return);

    analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertNothingSent();
});

it('markDone marks the row early and skips the notification during a fresh connect\'s early pass', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertNothingSent();
    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone leaves narrated_early_at null and notifies normally for a long-connected athlete', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create(['created_at' => now()->subDays(90)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertSentTo($user, AnalysisReadyNotification::class);
    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('markDone never marks a rule-based fill early, even for a run inside the early-pass window', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'filler.', ServedBy::RuleBased);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('never marks a rule-based fill early from $startedEarly either', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'filler.', ServedBy::RuleBased, startedEarly: true);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('markDone marks the daily briefing early while older history is hydrating', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => now()->toDateString(),
    ]);

    analysisRowState()->markDone($row, 'Halo!', ServedBy::Llm);

    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone marks the profile voice early while any history at all is hydrating, even past past-you\'s reach', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    // Beyond PastYouMatcher::MAX_GAP_DAYS (365): awaitsOlderHydration() would
    // ignore this, but the profile voice reads the whole lifetime, so
    // awaitsFullHydration() must still catch it.
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(400)]);
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::ProfileVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::ProfileVoice,
        'discriminator' => AnalysisType::currentIsoWeek(),
    ]);

    analysisRowState()->markDone($row, 'Profile voice.', ServedBy::Llm);

    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone does not notify for a non-notifiable type', function (): void {
    Notification::fake();
    $row = Analysis::factory()->create(['analysis_type' => AnalysisType::BriefingMascotVoice]);

    analysisRowState()->markDone($row, 'Halo!', ServedBy::Llm);

    Notification::assertNothingSent();
});

it('markDone does not notify under withoutDispatching (demo seed)', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    app(NarrationGate::class)->withoutDispatching(function () use ($row): void {
        analysisRowState()->markDone($row, 'Rekap seed.', ServedBy::Llm);
    });

    Notification::assertNothingSent();
});

it('markDone does not start the re-trigger cooldown under withoutDispatching (demo seed)', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    app(NarrationGate::class)->withoutDispatching(function () use ($row): void {
        analysisRowState()->markDone($row, 'Seed content.', ServedBy::Llm);
    });

    $fresh = nonNull($row->fresh());
    expect($fresh->cooldownRemaining())->toBeNull()
        ->and($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('Seed content.');
});

it('markDone reaches the inbox alone when Telegram is unconfigured', function (): void {
    config(['services.telegram.bot_token' => null, 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisRowState()->markDone($row, 'Rekap.', ServedBy::Llm);

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->via($user) === [InAppChannel::class],
    );
});

it('requestRuleBased fills a row rule-based with no reason, without dispatching or cooling, and refills it in place rather than minting a second one', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $first = analysisRowState()->serveRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($first->status)->toBe(AnalysisStatus::Done)
        ->and($first->content)->toBeString()->and($first->content)->not->toBeEmpty()
        ->and($first->generated_at)->not->toBeNull()
        ->and($first->cooldownRemaining())->toBeNull()
        ->and(nonNull($first->fresh())->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($first->fresh())->rule_based_reason)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    $second = analysisRowState()->serveRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($second->id)->toBe($first->id)
        ->and(Analysis::query()->count())->toBe(1);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestRuleBased with refillDone:false leaves an already-Done row untouched', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('original recap, already billed')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $row = analysisRowState()->serveRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap, refillDone: false);

    expect($row->content)->toBe('original recap, already billed');
});

// ── served_by: which producer wrote the content on the row ────────────

it('stamps the producer the caller states, and has no default to fall back on', function (): void {
    $llm = Analysis::factory()->queued()->create(['discriminator' => '2026-05-18']);
    $ruleBased = Analysis::factory()->queued()->create(['discriminator' => '2026-05-19']);

    analysisRowState()->markDone($llm, 'narrated', ServedBy::Llm);
    analysisRowState()->markDone($ruleBased, 'filled', ServedBy::RuleBased);

    expect(nonNull($llm->fresh())->served_by)->toBe(ServedBy::Llm)
        ->and(nonNull($ruleBased->fresh())->served_by)->toBe(ServedBy::RuleBased)
        ->and(new ReflectionMethod(AnalysisRowState::class, 'markDone')->getParameters()[2]->isOptional())
        ->toBeFalse();
});

it('does not count a rule-based fill after a newer generation takes ownership', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisRowState()->stagePending(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    $row->update(['generation_token' => 'older-generation']);
    $stale = $row->fresh();
    $row->update([
        'status' => AnalysisStatus::Queued,
        'generation_token' => 'newer-generation',
    ]);
    $ledger = app(CostCeilingLedger::class);
    $degradedFills = $ledger->today()['degradedFills'];

    analysisRowState()->degradeToRuleBased(nonNull($stale), 'older-generation');

    expect(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Queued)
        ->and(nonNull($row->fresh())->generation_token)->toBe('newer-generation')
        ->and(nonNull($row->fresh())->content)->toBeNull()
        ->and($ledger->today()['degradedFills'])->toBe($degradedFills);
});

// ── rule_based_reason: why (not merely that) a row was served rule-based ──

it('never records a reason for an LLM-served row, even if one is passed', function (): void {
    $row = Analysis::factory()->queued()->create();

    analysisRowState()->markDone($row, 'narrated', ServedBy::Llm, ruleBasedReason: AnalysisOrigin::Return);

    expect(nonNull($row->fresh())->rule_based_reason)->toBeNull();
});

it('clears a stale rule_based_reason when the row is re-served by the LLM', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisRowState()->serveRuleBased(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
        reason: AnalysisOrigin::Return,
    );
    expect(nonNull($row->fresh())->rule_based_reason)->toBe(AnalysisOrigin::Return);

    analysisRowState()->markDone($row, 'now narrated for real', ServedBy::Llm);

    $fresh = nonNull($row->fresh());

    expect($fresh->served_by)->toBe(ServedBy::Llm)
        ->and($fresh->rule_based_reason)->toBeNull();
});

it('clears a stale rule_based_reason when re-served rule-based for a different reason', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisRowState()->serveRuleBased(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
        reason: AnalysisOrigin::Return,
    );

    analysisRowState()->serveRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect(nonNull($row->fresh())->rule_based_reason)->toBeNull();
});

// ── analysis_versions: what a re-narration supersedes ─────────────────

it('keeps the previous narration and supersedes only its own flag when a done row is re-narrated', function (): void {
    $row = Analysis::factory()->done('first')->create([
        'content_fingerprint' => 'abc123',
        'served_by' => ServedBy::RuleBased,
    ]);
    $flag = Feedback::factory()->onNarration($row->id)->create();
    $otherNarration = Feedback::factory()->onNarration($row->id + 1)->create();
    $planDay = Feedback::factory()->onPlanDay($row->id)->create();

    analysisRowState()->markDone($row, 'second', ServedBy::Llm);

    $version = AnalysisVersion::query()->sole();

    expect($version->analysis_id)->toBe($row->id)
        ->and($version->content)->toBe('first')
        ->and($version->fingerprint)->toBe('abc123')
        ->and($version->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($row->fresh())->content)->toBe('second')
        ->and(nonNull($flag->fresh())->superseded_at)->not->toBeNull()
        ->and(nonNull($otherNarration->fresh())->superseded_at)->toBeNull()
        ->and(nonNull($planDay->fresh())->superseded_at)->toBeNull();
});

it('writes no version and leaves the flag standing on a first narration, which supersedes nothing', function (): void {
    $row = Analysis::factory()->queued()->create();
    $flag = Feedback::factory()->onNarration($row->id)->create();

    analysisRowState()->markDone($row, 'first', ServedBy::Llm);

    expect(AnalysisVersion::query()->count())->toBe(0)
        ->and(nonNull($flag->fresh())->superseded_at)->toBeNull();
});

it('opens the Reread cooldown only after a user-started narration', function (AnalysisOrigin $origin, bool $cooling): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);
    app(NarrationOrigin::class)->set($origin);

    analysisRowState()->markDone($row, 'story', ServedBy::Llm);

    expect(nonNull($row->fresh())->cooldownRemaining() !== null)->toBe($cooling);
})->with([
    'ingest' => [AnalysisOrigin::Ingest, false],
    'self-heal' => [AnalysisOrigin::Recovery, false],
    'scheduled' => [AnalysisOrigin::Scheduled, false],
    'user' => [AnalysisOrigin::User, true],
]);

function straddlingRow(): Analysis
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);

    return Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
}

it('fires EarlyPassStraddled for a straddling row instead of marking it early', function (): void {
    Event::fake([EarlyPassStraddled::class]);
    $row = straddlingRow();

    analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm, startedEarly: true);

    Event::assertDispatchedTimes(EarlyPassStraddled::class, 1);
    Event::assertDispatched(fn (EarlyPassStraddled $event): bool => $event->analysis === $row);
    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('fires EarlyPassStraddled only once the surrounding transaction commits', function (): void {
    Event::fake([EarlyPassStraddled::class]);
    $row = straddlingRow();

    DB::transaction(function () use ($row): void {
        analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm, startedEarly: true);

        Event::assertNotDispatched(EarlyPassStraddled::class);
    });

    Event::assertDispatchedTimes(EarlyPassStraddled::class, 1);
});

it('fires no EarlyPassStraddled under withoutDispatching', function (): void {
    Event::fake([EarlyPassStraddled::class]);
    $row = straddlingRow();

    app(NarrationGate::class)->withoutDispatching(function () use ($row): void {
        analysisRowState()->markDone($row, 'Run story.', ServedBy::Llm, startedEarly: true);
    });

    Event::assertNotDispatched(EarlyPassStraddled::class);
    expect(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Done);
});
