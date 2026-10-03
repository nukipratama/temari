<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\HydrationBacklog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('continues dispatching later briefings after one athlete fails and alerts once', function (): void {
    Bus::fake();
    Config::set('services.telegram.bot_token', 'test-bot-token');
    Carbon::setTestNow('2026-05-11 00:01:00');
    $failing = User::factory()->seenToday()->create(['name' => 'Private Failed Athlete']);
    $later = User::factory()->seenToday()->create(['name' => 'Private Later Athlete']);
    PlannedSession::factory()->for($failing)->create(['date' => Carbon::today()]);
    PlannedSession::factory()->for($later)->create(['date' => Carbon::today()]);

    $realBacklog = app(HydrationBacklog::class);
    $backlog = Mockery::mock(HydrationBacklog::class);
    $backlog->shouldReceive('recentLoadAwaitsScoring')->andReturnUsing(
        static function (int $userId, Carbon $today) use ($failing, $realBacklog): bool {
            if ($userId === $failing->id) {
                throw new LogicException('test briefing failure');
            }

            return $realBacklog->recentLoadAwaitsScoring($userId, $today);
        },
    );
    $this->app->instance(HydrationBacklog::class, $backlog);

    $requested = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestBriefing')->andReturnUsing(
        static function (User $user, string $day) use (&$requested): Analysis {
            $requested[] = [$user->id, $day];

            return new Analysis();
        },
    );
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')->assertSuccessful();

    expect($requested)->toBe([[$later->id, Carbon::today()->toDateString()]]);
    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
    Bus::assertDispatched(static fn (SendMaintainerAlertJob $job): bool =>
        str_contains($job->message, 'ai:daily-briefing')
        && str_contains($job->message, '1 athlete')
        && ! str_contains($job->message, 'Private Failed Athlete')
        && ! str_contains($job->message, 'Private Later Athlete'));

    Carbon::setTestNow();
});

it('dispatches the briefing group for each active user, and nothing else', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');
    $today = Carbon::today()->toDateString();

    $user = User::factory()->seenToday()->create();

    $briefingGroupCalls = [];

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestBriefing')
        ->once()
        ->andReturnUsing(function (User $u, string $discriminator) use (&$briefingGroupCalls): Analysis {
            $briefingGroupCalls[] = ['user_id' => $u->id, 'discriminator' => $discriminator];

            return new Analysis();
        });
    // The featured-card row was this command's only direct request() call.
    // W2 removed it with the panel it narrated, so the kickoff is the group alone.
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 1 active users.')
        ->assertSuccessful();

    expect($briefingGroupCalls)->toHaveCount(1);
    expect($briefingGroupCalls[0]['user_id'])->toBe($user->id)
        ->and($briefingGroupCalls[0]['discriminator'])->toBe($today);

    Carbon::setTestNow();
});

it('skips the demo user even with recent analyzed activity', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');

    User::factory()->seenToday()->create();
    User::factory()->demo()->seenToday()->create();

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestBriefing')->once()->andReturn(new Analysis());
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 1 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('a second same-day run never re-bills a Done row', function (): void {
    // ai:daily-briefing is idempotent: if it runs a second time the same day (a
    // manual re-trigger, or a developer re-run), it must NOT re-dispatch (and so
    // never re-bill) a row already Done.
    Carbon::setTestNow('2026-05-11 12:00:00');
    $today = Carbon::today()->toDateString();

    $user = User::factory()->seenToday()->create();

    // Simulate the earlier (00:01) run having already completed the mascot voice.
    Analysis::factory()->done('temari note yesterday')->create([
        'subject_type' => AnalysisType::BriefingMascotVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => $today,
    ]);

    // Real service + faked bus so we observe exactly what the re-run dispatches.
    Bus::fake();

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 1 active users.')
        ->assertSuccessful();

    // The already-Done type is neither re-dispatched nor reset (no re-bill).
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);

    $mascot = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', $today)
        ->firstOrFail();
    expect($mascot->status)->toBe(AnalysisStatus::Done)
        ->and($mascot->content)->toBe('temari note yesterday');

    Carbon::setTestNow();
});

it('reports zero active users when nobody has opened the app recently', function (): void {
    Carbon::setTestNow('2026-05-11 12:00:00');

    User::factory()->create(['last_seen_at' => Carbon::today()->subDays(15)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('requestBriefing');
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('skips an athlete who is running but not opening the app', function (): void {
    // A run syncs itself in without anyone looking, so run recency says nothing
    // about whether the briefing this bills for will ever be read.
    Carbon::setTestNow('2026-05-11 12:00:00');

    $dormant = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(15)]);
    $activity = Activity::factory()->for($dormant)->analyzed()->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('requestBriefing');
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

// A first connect late enough in the evening for its backlog drain to cross
// this 00:01 kickoff still gets today's briefing right away — the early
// pass, per docs/decisions/history-narrates-on-demand.md.
it('narrates the briefing right away for a first connect whose history is still hydrating', function (): void {
    Carbon::setTestNow('2026-06-10 00:01:00');
    $today = Carbon::today()->toDateString();

    $user = User::factory()->seenToday()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()]);
    // Within past-you's 365-day reach, still awaiting hydration.
    $older = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::now()->subDay()]);

    Bus::fake();

    $this->artisan('ai:daily-briefing')
        ->expectsOutputToContain('Dispatched daily kickoff (briefing) for 1 active users.')
        ->assertSuccessful();

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);

    $row = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', $today)
        ->firstOrFail();
    expect($row->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});
