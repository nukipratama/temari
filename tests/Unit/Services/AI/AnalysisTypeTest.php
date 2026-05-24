<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePersonaSummaryJob;
use App\Services\AI\AnalysisType;

it('maps PersonaSummary to its job + subject type', function (): void {
    expect(AnalysisType::PersonaSummary->jobClass())->toBe(AnalyzePersonaSummaryJob::class)
        ->and(AnalysisType::PersonaSummary->subjectType())->toBe(AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE);
});

it('maps MonthlyRecap to its job + subject type', function (): void {
    expect(AnalysisType::MonthlyRecap->jobClass())->toBe(AnalyzeMonthlyRecapJob::class)
        ->and(AnalysisType::MonthlyRecap->subjectType())->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE);
});
