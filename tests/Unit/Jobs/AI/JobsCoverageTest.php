<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzePostRunSpeechJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeRunInsightJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\BriefingNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use App\Services\AI\Narrators\TrendCaptionNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use App\Services\Run\Story\BriefingComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

// ── AnalyzeBriefingJob ────────────────────────────────────────────────

it('AnalyzeBriefingJob → headline returns headline payload', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);

    $row = rowOf(BriefingComposer::SUBJECT_TYPE, $user->id, AnalysisType::BriefingHeadline, '2026-05-18');
    (new AnalyzeBriefingJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('H');
});

it('AnalyzeBriefingJob → suggestion returns suggestion payload', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);

    $row = rowOf(BriefingComposer::SUBJECT_TYPE, $user->id, AnalysisType::BriefingSuggestion, '2026-05-18');
    (new AnalyzeBriefingJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('S');
});

it('AnalyzeBriefingJob throws when user missing', function (): void {
    $row = rowOf(BriefingComposer::SUBJECT_TYPE, 99999, AnalysisType::BriefingHeadline, '2026-05-18');
    (new AnalyzeBriefingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeBriefingJob default match arm throws', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);
    $row = rowOf(BriefingComposer::SUBJECT_TYPE, $user->id, AnalysisType::WeeklyRecap, '2026-05-18');
    (new AnalyzeBriefingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeRunInsightJob ──────────────────────────────────────────────

function runInsightFixture(): Activity
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return $activity;
}

it('AnalyzeRunInsightJob → technical returns technical', function (): void {
    $activity = runInsightFixture();
    mockNarrator(RunInsightNarrator::class, ['technical' => 'T', 'splits' => 'S', 'zones' => 'Z']);
    $row = rowOf(Activity::class, $activity->id, AnalysisType::RunInsightTechnical);
    (new AnalyzeRunInsightJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('T');
});

it('AnalyzeRunInsightJob → splits returns splits', function (): void {
    $activity = runInsightFixture();
    mockNarrator(RunInsightNarrator::class, ['technical' => 'T', 'splits' => 'S', 'zones' => 'Z']);
    $row = rowOf(Activity::class, $activity->id, AnalysisType::RunInsightSplits);
    (new AnalyzeRunInsightJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('S');
});

it('AnalyzeRunInsightJob → zones returns zones', function (): void {
    $activity = runInsightFixture();
    mockNarrator(RunInsightNarrator::class, ['technical' => 'T', 'splits' => 'S', 'zones' => 'Z']);
    $row = rowOf(Activity::class, $activity->id, AnalysisType::RunInsightZones);
    (new AnalyzeRunInsightJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('Z');
});

it('AnalyzeRunInsightJob throws when activity missing', function (): void {
    $row = rowOf(Activity::class, 99999, AnalysisType::RunInsightTechnical);
    (new AnalyzeRunInsightJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeRunInsightJob default match arm throws on unsupported type', function (): void {
    $activity = runInsightFixture();
    mockNarrator(RunInsightNarrator::class, ['technical' => 'T', 'splits' => 'S', 'zones' => 'Z']);
    $row = rowOf(Activity::class, $activity->id, AnalysisType::WeeklyRecap);
    (new AnalyzeRunInsightJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeCardFlavorJob ──────────────────────────────────────────────

it('AnalyzeCardFlavorJob returns flavor string', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    mockNarrator(CardFlavorNarrator::class, 'flavor text');
    $row = rowOf(RunCard::class, $card->id, AnalysisType::CardFlavor);
    (new AnalyzeCardFlavorJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('flavor text');
});

it('AnalyzeCardFlavorJob throws when card missing', function (): void {
    $row = rowOf(RunCard::class, 99999, AnalysisType::CardFlavor);
    (new AnalyzeCardFlavorJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzePostRunSpeechJob ───────────────────────────────────────────

it('AnalyzePostRunSpeechJob returns speech when storyLine exists', function (): void {
    $activity = runInsightFixture();
    StoryLine::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $activity->user_id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'glow',
    ]);

    mockNarrator(PostRunSpeechNarrator::class, 'nice run');
    $row = rowOf(Activity::class, $activity->id, AnalysisType::PostRunSpeech);
    (new AnalyzePostRunSpeechJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('nice run');
});

it('AnalyzePostRunSpeechJob throws when activity missing', function (): void {
    $row = rowOf(Activity::class, 99999, AnalysisType::PostRunSpeech);
    (new AnalyzePostRunSpeechJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzePostRunSpeechJob throws when storyLine missing', function (): void {
    $activity = runInsightFixture();
    mockNarrator(PostRunSpeechNarrator::class, 'nice');
    $row = rowOf(Activity::class, $activity->id, AnalysisType::PostRunSpeech);
    (new AnalyzePostRunSpeechJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzePrContextJob ───────────────────────────────────────────────

it('AnalyzePrContextJob returns flavor', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->for($user)->create();
    mockNarrator(PrContextNarrator::class, 'pr flavor');
    $row = rowOf(PersonalRecord::class, $pr->id, AnalysisType::PrContext);
    (new AnalyzePrContextJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('pr flavor');
});

it('AnalyzePrContextJob throws when PR missing', function (): void {
    $row = rowOf(PersonalRecord::class, 99999, AnalysisType::PrContext);
    (new AnalyzePrContextJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeWeeklyRecapJob ─────────────────────────────────────────────

it('AnalyzeWeeklyRecapJob returns narrative', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek()->toDateString(),
    ]);
    mockNarrator(WeeklyRecapNarrator::class, 'weekly narrative');
    $row = rowOf(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    (new AnalyzeWeeklyRecapJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('weekly narrative');
});

it('AnalyzeWeeklyRecapJob throws when snapshot missing', function (): void {
    $row = rowOf(WeeklySnapshot::class, 99999, AnalysisType::WeeklyRecap);
    (new AnalyzeWeeklyRecapJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeTrendCaptionJob ────────────────────────────────────────────

it('AnalyzeTrendCaptionJob returns caption with discriminator', function (): void {
    $user = User::factory()->create();
    mockNarrator(TrendCaptionNarrator::class, 'trend caption');
    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, $user->id, AnalysisType::TrendCaption, '2026-05-18');
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('trend caption');
});

it('AnalyzeTrendCaptionJob returns caption without discriminator (today fallback)', function (): void {
    $user = User::factory()->create();
    mockNarrator(TrendCaptionNarrator::class, 'today caption');
    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, $user->id, AnalysisType::TrendCaption, null);
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('today caption');
});

it('AnalyzeTrendCaptionJob throws when user missing', function (): void {
    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, 99999, AnalysisType::TrendCaption);
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeDailyGreetingJob ───────────────────────────────────────────

it('AnalyzeDailyGreetingJob returns greeting', function (): void {
    $user = User::factory()->create();
    mockNarrator(DailyGreetingNarrator::class, 'halo pagi');
    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, $user->id, AnalysisType::DailyGreeting, '2026-05-18');
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('halo pagi');
});

it('AnalyzeDailyGreetingJob throws when user missing', function (): void {
    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, 99999, AnalysisType::DailyGreeting);
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeDailyGreetingJob falls back to today when discriminator is null', function (): void {
    $user = User::factory()->create();
    mockNarrator(DailyGreetingNarrator::class, 'today halo');
    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, $user->id, AnalysisType::DailyGreeting, null);
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('today halo');
});

it('AnalyzeBriefingJob falls back to today when discriminator is null', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);
    $row = rowOf(BriefingComposer::SUBJECT_TYPE, $user->id, AnalysisType::BriefingHeadline, null);
    (new AnalyzeBriefingJob($row->id))->handle(app(AnalysisService::class));
    expect($row->fresh()->content)->toBe('H');
});
