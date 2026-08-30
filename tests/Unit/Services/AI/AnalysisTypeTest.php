<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\AnalyzePlanWeekVoiceJob;
use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Models\PlanAdaptation;
use App\Models\Season;
use App\Services\AI\AnalysisCadence;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;

it('pins the exact case list, so adding or retiring a type is a deliberate edit', function (): void {
    expect(array_column(AnalysisType::cases(), 'value'))->toBe([
        'briefing_mascot_voice',
        'briefing_featured_kartu_voice',
        'post_run_speech',
        'run_insight',
        'weekly_recap',
        'pr_context',
        'card_flavor',
        'aku_profile_voice',
        'monthly_recap',
        'trend_read',
        'plan_day_voice',
        'plan_week_voice',
        'plan_season_voice',
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

it('maps TrendRead to its job + subject type', function (): void {
    expect(AnalysisType::TrendRead->jobClass())->toBe(AnalyzeTrendReadJob::class)
        ->and(AnalysisType::TrendRead->subjectType())->toBe(AnalysisType::TREND_READ_SUBJECT_TYPE);
});

it('maps PlanDayVoice to its job + subject type', function (): void {
    expect(AnalysisType::PlanDayVoice->jobClass())->toBe(AnalyzePlanDayVoiceJob::class)
        ->and(AnalysisType::PlanDayVoice->subjectType())->toBe(AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE);
});

it('maps PlanWeekVoice to its job + subject type', function (): void {
    expect(AnalysisType::PlanWeekVoice->jobClass())->toBe(AnalyzePlanWeekVoiceJob::class)
        ->and(AnalysisType::PlanWeekVoice->subjectType())->toBe(PlanAdaptation::class);
});

it('maps PlanSeasonVoice to its job + subject type', function (): void {
    expect(AnalysisType::PlanSeasonVoice->jobClass())->toBe(AnalyzePlanSeasonVoiceJob::class)
        ->and(AnalysisType::PlanSeasonVoice->subjectType())->toBe(Season::class);
});

it('flags exactly the heart-rate-zone-derived types as zone-dependent', function (AnalysisType $type, bool $expected): void {
    expect($type->isZoneDependent())->toBe($expected);
})->with([
    // A claim's anchor can be zone-derived or not, and the row carries no flag
    // for which shape it landed on, so the whole type is treated as zone-dependent.
    'run insight' => [AnalysisType::RunInsight, true],
    'weekly recap' => [AnalysisType::WeeklyRecap, true],
    'monthly recap (reads zone-weighted CTL for its fitness arc)' => [AnalysisType::MonthlyRecap, true],
    'trend read (reads monotony/strain/CTL, all TRIMP-derived)' => [AnalysisType::TrendRead, true],
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
    'run insight (per-activity chain)' => [AnalysisType::RunInsight, true],
    'card flavor (standalone)' => [AnalysisType::CardFlavor, false],
    'briefing mascot voice (standalone)' => [AnalysisType::BriefingMascotVoice, false],
    'trend read (always as-of-now, never a sequence of closed periods)' => [AnalysisType::TrendRead, false],
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
    'trend read is on-demand (its own 3 cron schedules, not cascade-driven)' => [AnalysisType::TrendRead, AnalysisCadence::OnDemand],
    'plan day voice is daily' => [AnalysisType::PlanDayVoice, AnalysisCadence::Daily],
    'plan week voice is weekly' => [AnalysisType::PlanWeekVoice, AnalysisCadence::Weekly],
    'plan season voice is on-demand (changes only at season boundaries)' => [AnalysisType::PlanSeasonVoice, AnalysisCadence::OnDemand],
]);

it('is the single source of truth for group membership', function (): void {
    expect(AnalysisType::groupedBy(AnalyzeActivityJob::class))->toBe([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsight,
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
    'run insight' => [AnalysisType::RunInsight],
    'weekly recap' => [AnalysisType::WeeklyRecap],
    'pr context' => [AnalysisType::PrContext],
    'card flavor' => [AnalysisType::CardFlavor],
    'plan week voice' => [AnalysisType::PlanWeekVoice],
    'plan season voice' => [AnalysisType::PlanSeasonVoice],
]);

it('requires the date shape each keyed type dispatches with', function (AnalysisType $type, string $rule): void {
    expect($type->discriminatorRules())->toContain('required')->toContain($rule);
})->with([
    'briefing mascot voice is a day' => [AnalysisType::BriefingMascotVoice, 'date_format:Y-m-d'],
    'monthly recap is a month' => [AnalysisType::MonthlyRecap, 'date_format:Y-m'],
    'aku profile voice is an ISO week' => [AnalysisType::AkuProfileVoice, 'regex:/^\d{4}-W\d{2}$/'],
    'featured kartu is a card id' => [AnalysisType::BriefingFeaturedKartuVoice, 'regex:/^[1-9][0-9]*$/'],
    'plan day voice is a day' => [AnalysisType::PlanDayVoice, 'date_format:Y-m-d'],
]);

it('formats currentIsoWeek to the discriminator shape AkuProfileVoice requires', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');

    expect(AnalysisType::currentIsoWeek())->toBe('2026-W21')
        ->and(AnalysisType::currentIsoWeek())->toMatch('/^\d{4}-W\d{2}$/');

    Carbon::setTestNow();
});

/**
 * A shape rule is not a closed set. Every type that permits a discriminator must
 * bound *which* values it permits, or a caller can mint unbounded permanent rows
 * one request at a time. Two bounds are legitimate: a range, for a discriminator
 * naming a period, and an ownership check, for one naming a resource.
 */
it('bounds every discriminator it permits, by range or by ownership', function (): void {
    // The one resource-keyed discriminator. Its bound is ownership, enforced in
    // AnalysisSubjectAuthorizer and proved in that class's own suite, so a range
    // here would be meaningless.
    $boundedByOwnership = [AnalysisType::BriefingFeaturedKartuVoice];

    foreach (AnalysisType::cases() as $type) {
        $rules = $type->discriminatorRules();

        if ($rules === ['prohibited'] || in_array($type, $boundedByOwnership, true)) {
            continue;
        }

        $hasRange = collect($rules)->contains(
            fn (string|In $rule): bool => $rule instanceof In || str_starts_with((string) $rule, 'after_or_equal:'),
        );

        expect($hasRange)->toBeTrue(
            "[{$type->value}] permits a discriminator with a shape but no range, so a caller can mint "
            .'an unbounded number of permanent ai_analyses rows. Add a range, or bound it by ownership '
            .'in AnalysisSubjectAuthorizer and list it above.',
        );
    }
});

it('ranges each period discriminator against the age cap, not against wall clock drift', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');
    $oldestDay = Carbon::today()->subDays(AnalysisType::MAX_DISCRIMINATOR_AGE_DAYS)->toDateString();

    expect(AnalysisType::BriefingMascotVoice->discriminatorRules())
        ->toContain('after_or_equal:'.$oldestDay)
        ->toContain('before_or_equal:2026-05-18');

    Carbon::setTestNow();
});

it('bounds TrendRead\'s discriminator to exactly the three ranges', function (string $range, bool $valid): void {
    $result = Validator::make(
        ['discriminator' => $range],
        ['discriminator' => AnalysisType::TrendRead->discriminatorRules()],
    );

    expect($result->fails())->toBe(! $valid);
})->with([
    '30d' => ['30d', true],
    '90d' => ['90d', true],
    '12mo' => ['12mo', true],
    '7d (not one of the three)' => ['7d', false],
    'empty' => ['', false],
]);
