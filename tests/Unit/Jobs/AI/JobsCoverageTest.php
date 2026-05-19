<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\BriefingNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\TrendCaptionNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
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

// ── AnalyzeBriefingJob (group) ────────────────────────────────────────

it('AnalyzeBriefingJob writes both headline + suggestion rows Done', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);

    (new AnalyzeBriefingJob($user->id, '2026-05-18'))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', '2026-05-18')
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows[AnalysisType::BriefingHeadline->value]->content)->toBe('H')
        ->and($rows[AnalysisType::BriefingHeadline->value]->status)->toBe(AnalysisStatus::Done)
        ->and($rows[AnalysisType::BriefingSuggestion->value]->content)->toBe('S')
        ->and($rows[AnalysisType::BriefingSuggestion->value]->status)->toBe(AnalysisStatus::Done);
});

it('AnalyzeBriefingJob falls back to today when discriminator is null', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, ['headline' => 'H', 'suggestion' => 'S']);

    (new AnalyzeBriefingJob($user->id))->handle(app(AnalysisService::class));

    expect(Analysis::query()->where('subject_id', $user->id)->count())->toBe(2);
    Carbon::setTestNow();
});

it('AnalyzeBriefingJob marks all rows failed when user missing', function (): void {
    (new AnalyzeBriefingJob(99999, '2026-05-18'))->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', 99999)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

// ── AnalyzeCardFlavorJob (row) ────────────────────────────────────────

it('AnalyzeCardFlavorJob returns flavor string', function (): void {
    $card = RunCard::factory()->create();
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

// ── AnalyzePrContextJob (row) ────────────────────────────────────────

it('AnalyzePrContextJob returns flavor', function (): void {
    $pr = PersonalRecord::factory()->create();
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

// ── AnalyzeWeeklyRecapJob (row) ──────────────────────────────────────

it('AnalyzeWeeklyRecapJob returns narrative', function (): void {
    $snap = WeeklySnapshot::factory()->create([
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

// ── AnalyzeTrendCaptionJob (row) ─────────────────────────────────────

it('AnalyzeTrendCaptionJob returns caption with discriminator', function (): void {
    $user = User::factory()->create();
    mockNarrator(TrendCaptionNarrator::class, 'trend caption');

    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, $user->id, AnalysisType::TrendCaption, '2026-05-18');
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('trend caption');
});

it('AnalyzeTrendCaptionJob falls back to today when discriminator is null', function (): void {
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

// ── AnalyzeDailyGreetingJob (row) ────────────────────────────────────

it('AnalyzeDailyGreetingJob returns greeting', function (): void {
    $user = User::factory()->create();
    mockNarrator(DailyGreetingNarrator::class, 'halo pagi');

    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, $user->id, AnalysisType::DailyGreeting, '2026-05-18');
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('halo pagi');
});

it('AnalyzeDailyGreetingJob falls back to today when discriminator is null', function (): void {
    $user = User::factory()->create();
    mockNarrator(DailyGreetingNarrator::class, 'today halo');

    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, $user->id, AnalysisType::DailyGreeting, null);
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('today halo');
});

it('AnalyzeDailyGreetingJob throws when user missing', function (): void {
    $row = rowOf(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, 99999, AnalysisType::DailyGreeting);
    (new AnalyzeDailyGreetingJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});
