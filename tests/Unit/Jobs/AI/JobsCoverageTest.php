<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePlanClampVoiceJob;
use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\TrendReadFingerprint;
use App\Services\AI\Narrators\ProfileVoiceNarrator;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use App\Services\AI\Narrators\PlanClampVoiceNarrator;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Services\AI\Narrators\TrendReadNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mockNarrator(string $abstract, mixed $payload): void
{
    $mock = Mockery::mock($abstract);
    $mock->shouldReceive('generate')->andReturn($payload);
    app()->instance($abstract, $mock);
}

function rowOf(string $subjectType, int $subjectId, AnalysisType $type, ?string $discriminator = null): Analysis
{
    return Analysis::factory()->queued()->create([
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'analysis_type' => $type,
        'discriminator' => $discriminator,
    ]);
}

// ── AnalyzeBriefingMascotVoiceJob (row) ──────────────────────────────

it('AnalyzeBriefingMascotVoiceJob returns the mascot voice line', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingMascotVoiceNarrator::class, 'Temari note today');

    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18');
    new AnalyzeBriefingMascotVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('Temari note today')
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done);
});

it('AnalyzeBriefingMascotVoiceJob falls back to today when discriminator is null', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $user = User::factory()->create();
    mockNarrator(BriefingMascotVoiceNarrator::class, 'today mascot voice');

    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, null);
    new AnalyzeBriefingMascotVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('today mascot voice')
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done);
    Carbon::setTestNow();
});

it('AnalyzeBriefingMascotVoiceJob marks the row Failed and rethrows when the user is missing', function (): void {
    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, 99999, AnalysisType::BriefingMascotVoice, '2026-05-18');

    expect(fn () => new AnalyzeBriefingMascotVoiceJob($row->id)->handle(app(AnalysisService::class)))
        ->toThrow(ModelNotFoundException::class);

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeTrendReadJob (row) ─────────────────────────────────────────

it('AnalyzeTrendReadJob returns the trend read for the row\'s range', function (): void {
    $user = User::factory()->create();
    mockNarrator(TrendReadNarrator::class, 'The last 30 days, in one read.');

    $row = rowOf(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, '7d');
    new AnalyzeTrendReadJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('The last 30 days, in one read.')
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done);
});

it('AnalyzeTrendReadJob stamps the range\'s current fingerprint once done', function (): void {
    $user = User::factory()->create();
    mockNarrator(TrendReadNarrator::class, 'The last 30 days, in one read.');

    $row = rowOf(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, '7d');
    new AnalyzeTrendReadJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content_fingerprint)->toBe(app(TrendReadFingerprint::class)->forUser($user, '7d'));
});

it('AnalyzeTrendReadJob marks the row Failed and rethrows when the user is missing', function (): void {
    $row = rowOf(AnalysisType::TREND_READ_SUBJECT_TYPE, 99999, AnalysisType::TrendRead, '7d');

    expect(fn () => new AnalyzeTrendReadJob($row->id)->handle(app(AnalysisService::class)))
        ->toThrow(ModelNotFoundException::class);

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzePlanClampVoiceJob (row) ────────────────────────────────────

function clampedUser(): User
{
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'interval',
    ]);

    return $user;
}

function clampRowOf(User $user): Analysis
{
    return rowOf(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, Carbon::today()->toDateString());
}

it('AnalyzePlanClampVoiceJob stores the clamp voice and the clamp fingerprint on a Done row', function (): void {
    $user = clampedUser();
    mockNarrator(PlanClampVoiceNarrator::class, 'you ran long yesterday, so today is an easy day.');

    $row = clampRowOf($user);
    new AnalyzePlanClampVoiceJob($row->id)->handle(app(AnalysisService::class));

    $context = nonNull(app(ClampNarrationContext::class)->forUserOn($user->id, Carbon::today()));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->refresh()->content)->toBe('you ran long yesterday, so today is an easy day.')
        ->and($row->refresh()->content_fingerprint)->toBe(MaterialFingerprint::forClamp(
            $context['ceiling'],
            $context['clamped_to'],
            $context['has_run_today'],
            $context['readiness_reasons'],
        ));
});

it('AnalyzePlanClampVoiceJob deletes the row as obsolete when the day no longer clamps', function (): void {
    $user = User::factory()->create();
    $mock = Mockery::mock(PlanClampVoiceNarrator::class);
    $mock->shouldNotReceive('generate');
    app()->instance(PlanClampVoiceNarrator::class, $mock);

    $row = clampRowOf($user);
    new AnalyzePlanClampVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect(Analysis::query()->find($row->id))->toBeNull();
});

it('AnalyzePlanClampVoiceJob fingerprints the same clamp identically for different athletes', function (): void {
    mockNarrator(PlanClampVoiceNarrator::class, 'a rest day.');

    $first = clampRowOf(clampedUser());
    $second = clampRowOf(clampedUser());
    new AnalyzePlanClampVoiceJob($first->id)->handle(app(AnalysisService::class));
    new AnalyzePlanClampVoiceJob($second->id)->handle(app(AnalysisService::class));

    expect($first->refresh()->content_fingerprint)->not->toBeNull()
        ->and($second->refresh()->content_fingerprint)->toBe($first->refresh()->content_fingerprint);
});

// ── AnalyzeCardFlavorJob (row) ────────────────────────────────────────

it('AnalyzeCardFlavorJob returns flavor string', function (): void {
    $card = RunCard::factory()->create();
    mockNarrator(CardFlavorNarrator::class, 'flavor text');

    $row = rowOf(RunCard::class, $card->id, AnalysisType::CardFlavor);
    new AnalyzeCardFlavorJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('flavor text');
});

it('AnalyzeCardFlavorJob throws when card missing', function (): void {
    $row = rowOf(RunCard::class, 99999, AnalysisType::CardFlavor);
    new AnalyzeCardFlavorJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeCardFlavorJob stamps the card flavor row with the run material fingerprint', function (): void {
    $activity = Activity::factory()->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);
    $row = rowOf(RunCard::class, $card->id, AnalysisType::CardFlavor);

    $fingerprint = (fn (): ?string => $this->fingerprintFor($row))->call(new AnalyzeCardFlavorJob($row->id));

    expect($fingerprint)->toBe(MaterialFingerprint::forActivity($activity->refresh()));
});

// ── AnalyzeWeeklyRecapJob (row) ──────────────────────────────────────

it('AnalyzeWeeklyRecapJob returns narrative', function (): void {
    $snap = WeeklySnapshot::factory()->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    mockNarrator(WeeklyRecapNarrator::class, 'weekly narrative');

    $row = rowOf(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    new AnalyzeWeeklyRecapJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('weekly narrative');
});

it('AnalyzeWeeklyRecapJob throws when snapshot missing', function (): void {
    $row = rowOf(WeeklySnapshot::class, 99999, AnalysisType::WeeklyRecap);
    new AnalyzeWeeklyRecapJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeWeeklyRecapJob advances the chain to the next Pending week on completion', function (): void {
    // Auto-dispatch needs configured Azure creds + ai.enabled (default true).
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('ai.backfill_stagger_seconds', 7);
    Bus::fake();

    $user = User::factory()->create();
    $thisWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 3]);
    $nextWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    // The next week's recap is pre-staged Pending so the chain has a link to walk to.
    $nextRow = Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $nextWeek->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    mockNarrator(WeeklyRecapNarrator::class, 'this week narrative');
    $thisRow = rowOf(WeeklySnapshot::class, $thisWeek->id, AnalysisType::WeeklyRecap);

    new AnalyzeWeeklyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    expect($thisRow->refresh()->content)->toBe('this week narrative')
        ->and($nextRow->refresh()->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('AnalyzeWeeklyRecapJob does not advance when no later Pending week exists', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    Bus::fake();

    $user = User::factory()->create();
    $thisWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    mockNarrator(WeeklyRecapNarrator::class, 'tail narrative');
    $thisRow = rowOf(WeeklySnapshot::class, $thisWeek->id, AnalysisType::WeeklyRecap);

    new AnalyzeWeeklyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    expect($thisRow->refresh()->content)->toBe('tail narrative');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('AnalyzeWeeklyRecapJob does not advance into the still-open current week', function (): void {
    Carbon::setTestNow('2026-05-18'); // latest fully-closed week ends 2026-05-17
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    Bus::fake();

    $user = User::factory()->create();
    $lastClosed = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    $openWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 2]);
    $openRow = Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $openWeek->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    mockNarrator(WeeklyRecapNarrator::class, 'closed week narrative');
    $thisRow = rowOf(WeeklySnapshot::class, $lastClosed->id, AnalysisType::WeeklyRecap);
    new AnalyzeWeeklyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    // The open week stays Pending: the chain must not narrate an incomplete week.
    expect($openRow->refresh()->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
    Carbon::setTestNow();
});

it('AnalyzeMonthlyRecapJob does not advance into the still-open current month', function (): void {
    Carbon::setTestNow('2026-05-18'); // latest fully-closed month is 2026-04
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    Bus::fake();

    $user = User::factory()->create();
    $openRow = Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'status' => AnalysisStatus::Pending,
    ]);

    mockNarrator(MonthlyRecapNarrator::class, 'closed month narrative');
    $thisRow = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-04');
    new AnalyzeMonthlyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    expect($openRow->refresh()->status)->toBe(AnalysisStatus::Pending);
    Carbon::setTestNow();
});

// ── AnalyzeProfileVoiceJob (row) ───────────────────────────────────

it('AnalyzeProfileVoiceJob returns profile voice', function (): void {
    $user = User::factory()->create();
    mockNarrator(ProfileVoiceNarrator::class, 'profile voice narrative');

    $row = rowOf(AnalysisType::PROFILE_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::ProfileVoice);
    new AnalyzeProfileVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('profile voice narrative');
});

it('AnalyzeProfileVoiceJob fails when user missing', function (): void {
    $row = rowOf(AnalysisType::PROFILE_VOICE_SUBJECT_TYPE, 99999, AnalysisType::ProfileVoice);
    new AnalyzeProfileVoiceJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeMonthlyRecapJob (row) ──────────────────────────────────────

it('AnalyzeMonthlyRecapJob returns recap for the given month', function (): void {
    $user = User::factory()->create();
    mockNarrator(MonthlyRecapNarrator::class, 'monthly narrative');

    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-05');
    new AnalyzeMonthlyRecapJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->content)->toBe('monthly narrative');
});

it('AnalyzeMonthlyRecapJob fails when user missing', function (): void {
    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, 99999, AnalysisType::MonthlyRecap, '2026-05');
    new AnalyzeMonthlyRecapJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeMonthlyRecapJob fails when discriminator is missing', function (): void {
    $user = User::factory()->create();
    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, null);
    new AnalyzeMonthlyRecapJob($row->id)->handle(app(AnalysisService::class));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeMonthlyRecapJob advances the chain to the next Pending month on completion', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('ai.backfill_stagger_seconds', 7);
    Bus::fake();

    $user = User::factory()->create();
    // The next month's recap is pre-staged Pending so the chain has a link to walk to.
    $nextRow = Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-05',
        'status' => AnalysisStatus::Pending,
    ]);

    mockNarrator(MonthlyRecapNarrator::class, 'this month narrative');
    $thisRow = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-04');

    new AnalyzeMonthlyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    expect($thisRow->refresh()->content)->toBe('this month narrative')
        ->and($nextRow->refresh()->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeMonthlyRecapJob::class);
});

it('AnalyzeMonthlyRecapJob does not advance when no later Pending month exists', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    Bus::fake();

    $user = User::factory()->create();

    mockNarrator(MonthlyRecapNarrator::class, 'tail narrative');
    $thisRow = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-05');

    new AnalyzeMonthlyRecapJob($thisRow->id)->handle(app(AnalysisService::class));

    expect($thisRow->refresh()->content)->toBe('tail narrative');
    Bus::assertNotDispatched(AnalyzeMonthlyRecapJob::class);
});

it('sends every analyze job to the narration queue, whichever base it extends', function (string $job): void {
    // Not configurable and not the dispatcher's business: a tool-calling run
    // needs the longer timeout only supervisor-ai gives it.
    Queue::fake();

    $job::dispatch(1);

    Queue::assertPushedOn('ai', $job);
    expect(AnalyzeBaseJob::QUEUE)->toBe('ai');
})->with([
    AnalyzeActivityJob::class => [AnalyzeActivityJob::class],
    AnalyzeBriefingMascotVoiceJob::class => [AnalyzeBriefingMascotVoiceJob::class],
    AnalyzeCardFlavorJob::class => [AnalyzeCardFlavorJob::class],
    AnalyzeMonthlyRecapJob::class => [AnalyzeMonthlyRecapJob::class],
    AnalyzePlanClampVoiceJob::class => [AnalyzePlanClampVoiceJob::class],
    AnalyzeProfileVoiceJob::class => [AnalyzeProfileVoiceJob::class],
    AnalyzeTrendReadJob::class => [AnalyzeTrendReadJob::class],
    AnalyzeWeeklyRecapJob::class => [AnalyzeWeeklyRecapJob::class],
]);
