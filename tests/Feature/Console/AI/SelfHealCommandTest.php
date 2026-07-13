<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// The resume sweep caps every chain at the latest fully-closed period
// (week_ending <= last-closed Sunday / discriminator <= last-closed month), so
// these tests are wall-clock dependent. Pin "now" to a fixed Wednesday whose
// last-closed week is 2026-06-14 and last-closed month is 2026-05.
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-17');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<int, array<string, mixed>>  $captured
 */
function captureResumeRequests(array &$captured): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('generationPaused')->andReturn(false);
    $service->shouldReceive('pauseReason')->andReturn(null);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'invalidate');

            return new Analysis();
        });
    // Per-activity chains advance through the group helper, not request().
    $service->shouldReceive('requestActivityGroup')
        ->andReturnUsing(function (Activity $activity, bool $invalidate = false, ?int $delaySeconds = null) use (&$captured): void {
            $captured[] = ['subjectOrType' => Activity::class, 'subjectId' => $activity->id, 'type' => AnalysisType::PostRunSpeech, 'discriminator' => null, 'invalidate' => $invalidate];
        });

    return $service;
}

/** A running (not paused) service mock that must never dispatch. */
function nonDispatchingResumeService(): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('generationPaused')->andReturn(false);
    $service->shouldReceive('pauseReason')->andReturn(null);
    $service->shouldNotReceive('request');
    $service->shouldNotReceive('requestActivityGroup');

    return $service;
}

/** Seed an activity for $user dated $startDate whose post-run speech is Pending. */
function pendingActivityChainLink(User $user, string $startDate): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startDate)]);
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    return $activity;
}

it('re-kicks the earliest Pending weekly link per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $earliest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-05', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $earliest->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    // A later Pending week must NOT be the one resumed (earliest wins).
    $later = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 4]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $later->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(WeeklySnapshot::class)
        ->and($captured[0]['subjectId'])->toBe($earliest->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('skips a demo user so the resume net never auto-bills its weekly LLM', function (): void {
    $demo = User::factory()->demo()->create();
    $snap = WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('re-kicks the earliest Pending monthly link per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-03',
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['discriminator'])->toBe('2026-03')
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('resumes both weekly and monthly chains in one sweep', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 2 blocks.')
        ->assertSuccessful();

    expect(array_column($captured, 'type'))
        ->toContain(AnalysisType::WeeklyRecap)
        ->toContain(AnalysisType::MonthlyRecap);
});

it('re-kicks the earliest Pending per-activity group per user', function (): void {
    $user = User::factory()->create();
    $earliest = pendingActivityChainLink($user, '2026-05-01 06:00:00');
    // A later Pending run must NOT be the one resumed (earliest wins).
    pendingActivityChainLink($user, '2026-05-10 06:00:00');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(Activity::class)
        ->and($captured[0]['subjectId'])->toBe($earliest->id)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('re-kicks the earliest stalled CardFlavor per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    Analysis::factory()->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(RunCard::class)
        ->and($captured[0]['subjectId'])->toBe($card->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::CardFlavor)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('recovers a Failed PrContext under the retry budget', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create(['set_at' => Carbon::parse('2026-05-01')]);
    Analysis::factory()->failed()->create([
        'subject_type' => PersonalRecord::class,
        'subject_id' => $pr->id,
        'analysis_type' => AnalysisType::PrContext,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(PersonalRecord::class)
        ->and($captured[0]['subjectId'])->toBe($pr->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::PrContext)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('leaves Done links alone (nothing stalled to resume)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->done('already narrated')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    Analysis::factory()->done('month narrated')->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-04',
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('recovers a Failed weekly link (not only Pending)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 3]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectId'])->toBe($snap->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('does not resume a Failed link that has burned its retry budget (dead-lettered)', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 3]);
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('recovers a Failed monthly link', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->failed()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['discriminator'])->toBe('2026-05');
});

it('skips the still-open current week (never narrates it early)', function (): void {
    // now = 2026-06-17: current week ends 2026-06-21 (> last closed 2026-06-14).
    $user = User::factory()->create();
    $open = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-06-21', 'runs' => 2]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $open->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('skips the still-open current month', function (): void {
    // now = 2026-06-17: current month 2026-06 (> last closed 2026-05).
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'status' => AnalysisStatus::Pending,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('re-kicks the earliest stalled BriefingHeadline group per user with invalidate:false', function (): void {
    $user = User::factory()->create();
    $earliest = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Pending,
    ]);
    // A later Pending briefing day must NOT be the one resumed (earliest wins).
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-06-01',
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::BriefingHeadline)
        ->and($captured[0]['discriminator'])->toBe($earliest->discriminator)
        ->and($captured[0]['invalidate'])->toBeFalse();
});

it('skips a demo user for the BriefingHeadline group so the resume net never auto-bills it', function (): void {
    $demo = User::factory()->demo()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $demo->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Pending,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('re-kicks the earliest stalled single-row block per user with invalidate:false', function (AnalysisType $type, string $subjectType, ?string $discriminator): void {
    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => $subjectType,
        'subject_id' => $user->id,
        'analysis_type' => $type,
        'discriminator' => $discriminator,
        'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureResumeRequests($captured));

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 1 blocks.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe($subjectType)
        ->and($captured[0]['subjectId'])->toBe($user->id)
        ->and($captured[0]['type'])->toBe($type)
        ->and($captured[0]['discriminator'])->toBe($discriminator)
        ->and($captured[0]['invalidate'])->toBeFalse();
})->with([
    'BriefingMascotVoice' => [AnalysisType::BriefingMascotVoice, AnalysisType::BRIEFING_SUBJECT_TYPE, '2026-05-18'],
    'BriefingFeaturedKartuVoice' => [AnalysisType::BriefingFeaturedKartuVoice, AnalysisType::BRIEFING_SUBJECT_TYPE, '42'],
    'DailyGreeting' => [AnalysisType::DailyGreeting, AnalysisType::DAILY_GREETING_SUBJECT_TYPE, '2026-05-18'],
    'TrendCaption' => [AnalysisType::TrendCaption, AnalysisType::TREND_CAPTION_SUBJECT_TYPE, '2026-05-18'],
    'PersonaSummary' => [AnalysisType::PersonaSummary, AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE, '2026-W21'],
    'AkuProfileVoice' => [AnalysisType::AkuProfileVoice, AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE, null],
]);

it('skips a demo user for a single-row type so the resume net never auto-bills it', function (): void {
    $demo = User::factory()->demo()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        'subject_id' => $demo->id,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Pending,
    ]);

    $this->app->instance(AnalysisService::class, nonDispatchingResumeService());

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 0 blocks.')
        ->assertSuccessful();
});

it('early-exits without sweeping when AI generation is paused', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('generationPaused')->andReturn(true);
    $service->shouldReceive('pauseReason')->andReturn('cost_ceiling');
    $service->shouldNotReceive('request');
    $service->shouldNotReceive('requestActivityGroup');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Skipped: AI generation is paused')
        ->assertSuccessful();
});
