<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePersonaSummaryJob;
use App\Services\AI\AnalysisCadence;
use App\Services\AI\AnalysisType;

it('maps PersonaSummary to its job + subject type', function (): void {
    expect(AnalysisType::PersonaSummary->jobClass())->toBe(AnalyzePersonaSummaryJob::class)
        ->and(AnalysisType::PersonaSummary->subjectType())->toBe(AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE);
});

it('maps MonthlyRecap to its job + subject type', function (): void {
    expect(AnalysisType::MonthlyRecap->jobClass())->toBe(AnalyzeMonthlyRecapJob::class)
        ->and(AnalysisType::MonthlyRecap->subjectType())->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE);
});

it('flags exactly the heart-rate-zone-derived types as zone-dependent', function (AnalysisType $type, bool $expected): void {
    expect($type->isZoneDependent())->toBe($expected);
})->with([
    'zones' => [AnalysisType::RunInsightZones, true],
    'weekly recap' => [AnalysisType::WeeklyRecap, true],
    'technical (uses run-peak HR, not zones)' => [AnalysisType::RunInsightTechnical, false],
    'splits' => [AnalysisType::RunInsightSplits, false],
    'post-run speech' => [AnalysisType::PostRunSpeech, false],
    'pr context' => [AnalysisType::PrContext, false],
    'briefing headline' => [AnalysisType::BriefingHeadline, false],
]);

it('flags only the connected + chained kinds wired so far', function (AnalysisType $type, bool $expected): void {
    expect($type->isChained())->toBe($expected);
})->with([
    'weekly recap (pilot)' => [AnalysisType::WeeklyRecap, true],
    'monthly recap (not yet wired)' => [AnalysisType::MonthlyRecap, false],
    'post-run speech (not yet wired)' => [AnalysisType::PostRunSpeech, false],
    'card flavor (standalone)' => [AnalysisType::CardFlavor, false],
    'briefing headline (standalone)' => [AnalysisType::BriefingHeadline, false],
]);

it('assigns a cadence to every type', function (): void {
    foreach (AnalysisType::cases() as $type) {
        expect($type->cadence())->toBeInstanceOf(AnalysisCadence::class);
    }
});

it('maps representative types to the expected cadence', function (AnalysisType $type, AnalysisCadence $expected): void {
    expect($type->cadence())->toBe($expected);
})->with([
    'post-run speech is per-activity' => [AnalysisType::PostRunSpeech, AnalysisCadence::PerActivity],
    'card flavor is per-activity' => [AnalysisType::CardFlavor, AnalysisCadence::PerActivity],
    'briefing headline is daily' => [AnalysisType::BriefingHeadline, AnalysisCadence::Daily],
    'daily greeting is daily' => [AnalysisType::DailyGreeting, AnalysisCadence::Daily],
    'trend caption is daily' => [AnalysisType::TrendCaption, AnalysisCadence::Daily],
    'weekly recap is weekly' => [AnalysisType::WeeklyRecap, AnalysisCadence::Weekly],
    'monthly recap is monthly' => [AnalysisType::MonthlyRecap, AnalysisCadence::Monthly],
    'persona summary is on-demand' => [AnalysisType::PersonaSummary, AnalysisCadence::OnDemand],
]);

it('is the single source of truth for group membership', function (): void {
    expect(AnalysisType::groupedBy(AnalyzeActivityJob::class))->toBe([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
    ])
        ->and(AnalysisType::groupedBy(AnalyzeBriefingJob::class))->toBe([
            AnalysisType::BriefingHeadline,
            AnalysisType::BriefingSuggestion,
        ])
        // The job classes derive their grouped types from the enum.
        ->and(AnalyzeActivityJob::groupedTypes())->toBe(AnalysisType::groupedBy(AnalyzeActivityJob::class))
        ->and(AnalyzeBriefingJob::groupedTypes())->toBe(AnalysisType::groupedBy(AnalyzeBriefingJob::class));
});

it('returns null group job for non-grouped types', function (AnalysisType $type): void {
    expect($type->groupJobClass())->toBeNull();
})->with([
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice],
    'daily greeting' => [AnalysisType::DailyGreeting],
    'weekly recap' => [AnalysisType::WeeklyRecap],
    'monthly recap' => [AnalysisType::MonthlyRecap],
]);
