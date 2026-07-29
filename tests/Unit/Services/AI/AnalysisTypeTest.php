<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Services\AI\AnalysisCadence;
use App\Services\AI\AnalysisType;

it('pins the exact case list, so adding or retiring a type is a deliberate edit', function (): void {
    expect(array_column(AnalysisType::cases(), 'value'))->toBe([
        'briefing_mascot_voice',
        'briefing_featured_kartu_voice',
        'post_run_speech',
        'run_insight_technical',
        'run_insight_splits',
        'run_insight_zones',
        'weekly_recap',
        'pr_context',
        'card_flavor',
        'aku_profile_voice',
        'monthly_recap',
    ], implode(' ', [
        'The AnalysisType case list changed. Update this list only after settling the call sites that',
        'read the cases as a set rather than one case at a time: Analysis::knownType(), which decides',
        'whether historical rows of a retired type stay re-dispatchable, the retired-type subject_type',
        'literals in UserEraser, since erasure must still reach rows whose case is gone, and',
        'discriminatorRules(), an exhaustive match with no default whose comma-separated arms PHP',
        'evaluates left to right, so one stale case name there is a fatal Error on every type at once.',
    ]));
});

it('maps AkuProfileVoice to its job + subject type', function (): void {
    expect(AnalysisType::AkuProfileVoice->jobClass())->toBe(AnalyzeAkuProfileVoiceJob::class)
        ->and(AnalysisType::AkuProfileVoice->subjectType())->toBe(AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE);
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
    'monthly recap (reads zone-weighted CTL for its fitness arc)' => [AnalysisType::MonthlyRecap, true],
    'technical (uses run-peak HR, not zones)' => [AnalysisType::RunInsightTechnical, false],
    'splits' => [AnalysisType::RunInsightSplits, false],
    'post-run speech' => [AnalysisType::PostRunSpeech, false],
    'pr context' => [AnalysisType::PrContext, false],
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice, false],
]);

it('flags only the connected + chained kinds wired so far', function (AnalysisType $type, bool $expected): void {
    expect($type->isChained())->toBe($expected);
})->with([
    'weekly recap (pilot)' => [AnalysisType::WeeklyRecap, true],
    'monthly recap (wired)' => [AnalysisType::MonthlyRecap, true],
    'post-run speech (per-activity chain)' => [AnalysisType::PostRunSpeech, true],
    'run insight technical (per-activity chain)' => [AnalysisType::RunInsightTechnical, true],
    'run insight splits (per-activity chain)' => [AnalysisType::RunInsightSplits, true],
    'run insight zones (per-activity chain)' => [AnalysisType::RunInsightZones, true],
    'card flavor (standalone)' => [AnalysisType::CardFlavor, false],
    'briefing mascot voice (standalone)' => [AnalysisType::BriefingMascotVoice, false],
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
    'weekly recap is weekly' => [AnalysisType::WeeklyRecap, AnalysisCadence::Weekly],
    'monthly recap is monthly' => [AnalysisType::MonthlyRecap, AnalysisCadence::Monthly],
    'aku profile voice is on-demand' => [AnalysisType::AkuProfileVoice, AnalysisCadence::OnDemand],
]);

it('is the single source of truth for group membership', function (): void {
    expect(AnalysisType::groupedBy(AnalyzeActivityJob::class))->toBe([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
    ])
        // The job class derives its grouped types from the enum.
        ->and(AnalyzeActivityJob::groupedTypes())->toBe(AnalysisType::groupedBy(AnalyzeActivityJob::class));
});

it('returns null group job for non-grouped types', function (AnalysisType $type): void {
    expect($type->groupJobClass())->toBeNull();
})->with([
    'briefing mascot voice' => [AnalysisType::BriefingMascotVoice],
    'briefing featured kartu voice' => [AnalysisType::BriefingFeaturedKartuVoice],
    'weekly recap' => [AnalysisType::WeeklyRecap],
    'monthly recap' => [AnalysisType::MonthlyRecap],
]);

it('gives every type a discriminator rule set', function (): void {
    foreach (AnalysisType::cases() as $case) {
        expect($case->discriminatorRules())->toBeArray()->not->toBeEmpty();
    }
});

it('prohibits a discriminator on the types that key off subject_id alone', function (AnalysisType $type): void {
    expect($type->discriminatorRules())->toBe(['prohibited']);
})->with([
    'post run speech' => [AnalysisType::PostRunSpeech],
    'run insight zones' => [AnalysisType::RunInsightZones],
    'weekly recap' => [AnalysisType::WeeklyRecap],
    'pr context' => [AnalysisType::PrContext],
    'card flavor' => [AnalysisType::CardFlavor],
]);

it('requires the date shape each keyed type dispatches with', function (AnalysisType $type, string $rule): void {
    expect($type->discriminatorRules())->toContain('required')->toContain($rule);
})->with([
    'briefing mascot voice is a day' => [AnalysisType::BriefingMascotVoice, 'date_format:Y-m-d'],
    'monthly recap is a month' => [AnalysisType::MonthlyRecap, 'date_format:Y-m'],
    'aku profile voice is an ISO week' => [AnalysisType::AkuProfileVoice, 'regex:/^\d{4}-W\d{2}$/'],
    'featured kartu is a card id' => [AnalysisType::BriefingFeaturedKartuVoice, 'regex:/^[1-9][0-9]*$/'],
]);
