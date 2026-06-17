<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePersonaSummaryJob;
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
use App\Services\AI\Narrators\AkuProfileVoiceNarrator;
use App\Services\AI\Narrators\BriefingMascotVoiceNarrator;
use App\Services\AI\Narrators\BriefingNarrator;
use App\Services\AI\Narrators\CardFlavorNarrator;
use App\Services\AI\Narrators\DailyGreetingNarrator;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use App\Services\AI\Narrators\PersonaSummaryNarrator;
use App\Services\AI\Narrators\PrContextNarrator;
use App\Services\AI\Narrators\WeeklyRecapNarrator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

it('AnalyzeBriefingJob writes the two briefing rows (headline + suggestion) Done', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingNarrator::class, [
        'headline' => 'H',
        'suggestion' => 'S',
    ]);

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
    mockNarrator(BriefingNarrator::class, [
        'headline' => 'H',
        'suggestion' => 'S',
    ]);

    (new AnalyzeBriefingJob($user->id))->handle(app(AnalysisService::class));

    expect(Analysis::query()->where('subject_id', $user->id)->count())->toBe(2);
    Carbon::setTestNow();
});

it('AnalyzeBriefingJob does not re-invoke the narrator when its rows are already Done (no double-bill)', function (): void {
    $user = User::factory()->create();

    // Pre-seed both group rows as Done so the idempotency guard short-circuits.
    foreach ([AnalysisType::BriefingHeadline, AnalysisType::BriefingSuggestion] as $type) {
        Analysis::factory()->done('preexisting')->create([
            'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => $type,
            'discriminator' => '2026-05-18',
        ]);
    }

    $mock = Mockery::mock(BriefingNarrator::class);
    $mock->shouldNotReceive('generate');
    app()->instance(BriefingNarrator::class, $mock);

    (new AnalyzeBriefingJob($user->id, '2026-05-18'))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('discriminator', '2026-05-18')
        ->get();

    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Done)
            ->and($row->content)->toBe('preexisting');
    }
});

it('AnalyzeBriefingJob marks all rows failed when user missing', function (): void {
    (new AnalyzeBriefingJob(99999, '2026-05-18'))->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', 99999)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

it('AnalyzeBriefingJob failed() marks every stranded group row Failed (and spares Done ones)', function (): void {
    $user = User::factory()->create();

    $headline = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Processing,
    ]);
    $suggestion = Analysis::factory()->done('kept')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingSuggestion,
        'discriminator' => '2026-05-18',
    ]);

    (new AnalyzeBriefingJob($user->id, '2026-05-18'))->failed(new RuntimeException('worker timeout'));

    expect($headline->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($headline->fresh()->error)->toBe('worker timeout')
        ->and($suggestion->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($suggestion->fresh()->content)->toBe('kept');
});

// ── AnalyzeBriefingMascotVoiceJob (row) ──────────────────────────────

it('AnalyzeBriefingMascotVoiceJob returns the mascot voice line', function (): void {
    $user = User::factory()->create();
    mockNarrator(BriefingMascotVoiceNarrator::class, 'Kata Temari hari ini');

    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18');
    (new AnalyzeBriefingMascotVoiceJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('Kata Temari hari ini')
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('AnalyzeBriefingMascotVoiceJob falls back to today when discriminator is null', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $user = User::factory()->create();
    mockNarrator(BriefingMascotVoiceNarrator::class, 'today mascot voice');

    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, null);
    (new AnalyzeBriefingMascotVoiceJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('today mascot voice')
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
    Carbon::setTestNow();
});

it('AnalyzeBriefingMascotVoiceJob marks the row Failed and rethrows when the user is missing', function (): void {
    $row = rowOf(AnalysisType::BRIEFING_SUBJECT_TYPE, 99999, AnalysisType::BriefingMascotVoice, '2026-05-18');

    expect(fn () => (new AnalyzeBriefingMascotVoiceJob($row->id))->handle(app(AnalysisService::class)))
        ->toThrow(ModelNotFoundException::class);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
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

it('AnalyzeWeeklyRecapJob advances the chain to the next Pending week on completion', function (): void {
    // Auto-dispatch needs configured Azure creds + ai.enabled (default true).
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    config()->set('ai.backfill_stagger_seconds', 7);
    Illuminate\Support\Facades\Bus::fake();

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

    (new AnalyzeWeeklyRecapJob($thisRow->id))->handle(app(AnalysisService::class));

    expect($thisRow->fresh()->content)->toBe('this week narrative')
        ->and($nextRow->fresh()->status)->toBe(AnalysisStatus::Queued);
    Illuminate\Support\Facades\Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('AnalyzeWeeklyRecapJob does not advance when no later Pending week exists', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake-key');
    Illuminate\Support\Facades\Bus::fake();

    $user = User::factory()->create();
    $thisWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    mockNarrator(WeeklyRecapNarrator::class, 'tail narrative');
    $thisRow = rowOf(WeeklySnapshot::class, $thisWeek->id, AnalysisType::WeeklyRecap);

    (new AnalyzeWeeklyRecapJob($thisRow->id))->handle(app(AnalysisService::class));

    expect($thisRow->fresh()->content)->toBe('tail narrative');
    Illuminate\Support\Facades\Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

// ── AnalyzeTrendCaptionJob (row) ─────────────────────────────────────

it('AnalyzeTrendCaptionJob returns caption with discriminator', function (): void {
    $user = User::factory()->create();
    // Seed two weekly snapshots so RuleBasedInsightBuilder can produce a caption.
    WeeklySnapshot::factory()->create(['user_id' => $user->id, 'week_ending' => '2026-05-11', 'distance_km' => 20, 'form' => 10]);
    WeeklySnapshot::factory()->create(['user_id' => $user->id, 'week_ending' => '2026-05-18', 'distance_km' => 25, 'form' => 12]);

    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, $user->id, AnalysisType::TrendCaption, '2026-05-18');
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->not->toBeEmpty()
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('AnalyzeTrendCaptionJob falls back to today when discriminator is null', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $user = User::factory()->create();
    // Seed two weekly snapshots so RuleBasedInsightBuilder can produce a caption.
    WeeklySnapshot::factory()->create(['user_id' => $user->id, 'week_ending' => '2026-05-11', 'distance_km' => 20, 'form' => 10]);
    WeeklySnapshot::factory()->create(['user_id' => $user->id, 'week_ending' => '2026-05-18', 'distance_km' => 25, 'form' => 12]);

    $row = rowOf(AnalysisType::TREND_CAPTION_SUBJECT_TYPE, $user->id, AnalysisType::TrendCaption, null);
    (new AnalyzeTrendCaptionJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->not->toBeEmpty()
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
    Carbon::setTestNow();
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

// ── AnalyzePersonaSummaryJob (row) ────────────────────────────────────

it('AnalyzePersonaSummaryJob returns summary', function (): void {
    $user = User::factory()->create();
    mockNarrator(PersonaSummaryNarrator::class, 'persona narrative');

    $row = rowOf(AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE, $user->id, AnalysisType::PersonaSummary);
    (new AnalyzePersonaSummaryJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('persona narrative');
});

it('AnalyzePersonaSummaryJob fails when user missing', function (): void {
    $row = rowOf(AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE, 99999, AnalysisType::PersonaSummary);
    (new AnalyzePersonaSummaryJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeAkuProfileVoiceJob (row) ───────────────────────────────────

it('AnalyzeAkuProfileVoiceJob returns profile voice', function (): void {
    $user = User::factory()->create();
    mockNarrator(AkuProfileVoiceNarrator::class, 'profile voice narrative');

    $row = rowOf(AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::AkuProfileVoice);
    (new AnalyzeAkuProfileVoiceJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('profile voice narrative');
});

it('AnalyzeAkuProfileVoiceJob fails when user missing', function (): void {
    $row = rowOf(AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE, 99999, AnalysisType::AkuProfileVoice);
    (new AnalyzeAkuProfileVoiceJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

// ── AnalyzeMonthlyRecapJob (row) ──────────────────────────────────────

it('AnalyzeMonthlyRecapJob returns recap for the given month', function (): void {
    $user = User::factory()->create();
    mockNarrator(MonthlyRecapNarrator::class, 'monthly narrative');

    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-05');
    (new AnalyzeMonthlyRecapJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('monthly narrative');
});

it('AnalyzeMonthlyRecapJob fails when user missing', function (): void {
    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, 99999, AnalysisType::MonthlyRecap, '2026-05');
    (new AnalyzeMonthlyRecapJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('AnalyzeMonthlyRecapJob fails when discriminator is missing', function (): void {
    $user = User::factory()->create();
    $row = rowOf(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, null);
    (new AnalyzeMonthlyRecapJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});
