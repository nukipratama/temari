<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\Run\ReconcilePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Plan\MakeUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
});
afterEach(fn () => Carbon::setTestNow());

const MAKE_UP_CLAMP = [
    'clamped_km' => 3.0,
    'rest_clamped_at' => '2026-08-11 06:00:00',
    'eased_pace_sec_per_km' => 420,
    'readiness_assessment' => ['ceiling' => 'easy_only', 'reasons' => [], 'inputs' => []],
];

/**
 * A missed Easy already swapped off `$vacatedDate` onto `$targetDate`, where
 * a 5 km run with its narration and card already landed.
 *
 * @return array{User, PlannedSession, PlannedSession, Activity}
 */
function swappedMakeUp(string $vacatedDate, string $targetDate, array $userAttributes = []): array
{
    $user = User::factory()->create($userAttributes);
    $vacated = PlannedSession::factory()->for($user)->rest()->create([
        'date' => $vacatedDate,
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
        'distance_score' => 0,
        ...MAKE_UP_CLAMP,
    ]);
    $target = PlannedSession::factory()->for($user)->create([
        'date' => $targetDate,
        'session_type' => SessionType::Easy,
        ...MAKE_UP_CLAMP,
    ]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse("{$targetDate} 06:00:00"),
        'distance' => 5000,
        'moving_time' => 1800,
        'elapsed_time' => 1800,
    ]);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);
    Analysis::factory()->done()->create(['subject_type' => Activity::class, 'subject_id' => $activity->id, 'analysis_type' => AnalysisType::PostRunSpeech, 'discriminator' => null]);
    Analysis::factory()->done()->create(['subject_type' => RunCard::class, 'subject_id' => $card->id, 'analysis_type' => AnalysisType::CardFlavor, 'discriminator' => null]);
    Analysis::factory()->done()->create(['subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE, 'subject_id' => $user->id, 'analysis_type' => AnalysisType::BriefingMascotVoice, 'discriminator' => '2026-08-12']);

    return [$user, $vacated, $target, $activity];
}

function applyMakeUp(User $user, PlannedSession $vacated, PlannedSession $target): void
{
    DB::transaction(fn () => app(MakeUpService::class)->apply($user, $vacated, $target, Carbon::today()));
    app(MakeUpService::class)->notify($user, $vacated->date, $target->date, Carbon::today());
}

it('links the two days and clears the clamp state on both', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    foreach ([$vacated->fresh(), $target->fresh()] as $row) {
        expect($row->clamped_km)->toBeNull()
            ->and($row->rest_clamped_at)->toBeNull()
            ->and($row->eased_pace_sec_per_km)->toBeNull()
            ->and($row->readiness_assessment)->toBeNull();
    }
    expect($vacated->fresh()->made_up_on->toDateString())->toBe('2026-08-12')
        ->and($target->fresh()->made_up_from_id)->toBe($vacated->id);
});

it('regrades both days in full: the made-up day on the moved session, the emptied one as rest', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    expect($vacated->fresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($vacated->fresh()->distance_score)->toBeNull()
        ->and($vacated->fresh()->compliance_score)->toBeNull()
        ->and($target->fresh()->prescribed_km)->toBeGreaterThan(0.0)
        ->and($target->fresh()->distance_score)->toBeGreaterThan(0)
        ->and($target->fresh()->intent_evidence['advice_history'])->toBe('declared_after_run');
});

it('marks the plan for reconciliation from the earlier of the two days', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    expect($user->fresh()->plan_reconciliation_pending_from->toDateString())->toBe('2026-08-11');
    Bus::assertDispatched(ReconcilePlanJob::class);
});

it('re-reads the made-up day but not the emptied one, invalidates the made-up day\'s run narration, and rebriefs when the make-up lands today', function (): void {
    [$user, $vacated, $target, $activity] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    expect(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->pluck('discriminator')->sort()->values()->all())
        ->toBe(['2026-08-12'])
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PostRunSpeech)->sole()->status)->not->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::CardFlavor)->sole()->status)->not->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->not->toBe(AnalysisStatus::Done);
    Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('re-narrates the runs on the day the make-up emptied and reads that day, never by LLM for the demo athlete', function (bool $demo): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12', ['is_demo' => $demo]);
    $emptiedDayRun = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($emptiedDayRun)->create([
        'start_date_local' => Carbon::parse('2026-08-11 06:00:00'),
        'distance' => 1500,
        'moving_time' => 600,
        'elapsed_time' => 600,
    ]);
    $card = RunCard::factory()->create(['activity_id' => $emptiedDayRun->id]);
    $speech = Analysis::factory()->done()->create(['subject_type' => Activity::class, 'subject_id' => $emptiedDayRun->id, 'analysis_type' => AnalysisType::PostRunSpeech, 'discriminator' => null]);
    $flavor = Analysis::factory()->done()->create(['subject_type' => RunCard::class, 'subject_id' => $card->id, 'analysis_type' => AnalysisType::CardFlavor, 'discriminator' => null]);

    applyMakeUp($user, $vacated, $target);

    expect($speech->fresh()->status === AnalysisStatus::Done)->toBe($demo)
        ->and($flavor->fresh()->status === AnalysisStatus::Done)->toBe($demo)
        ->and($vacated->fresh()->ran_anyway)->toBeTrue()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->pluck('discriminator')->sort()->values()->all())->toBe(['2026-08-11', '2026-08-12']);
    if ($demo) {
        Bus::assertNothingDispatched();
    } else {
        Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $emptiedDayRun->id);
        Bus::assertDispatched(fn (AnalyzeCardFlavorJob $job): bool => $job->analysisId === $flavor->id);
    }
})->with(['athlete' => [false], 'demo' => [true]]);

it('leaves today\'s briefing alone when the make-up lands on an earlier day', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00');
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12');

    applyMakeUp($user, $vacated, $target);

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->toBe(AnalysisStatus::Done);
});

it('rebriefs when today\'s own session is the one made up onto an earlier day', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-12', '2026-08-11');

    applyMakeUp($user, $vacated, $target);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->not->toBe(AnalysisStatus::Done);
});

it('keeps the demo athlete rule-based, with no LLM call and no reconciliation', function (): void {
    [$user, $vacated, $target] = swappedMakeUp('2026-08-11', '2026-08-12', ['is_demo' => true]);

    applyMakeUp($user, $vacated, $target);

    Bus::assertNothingDispatched();
    expect($target->fresh()->intent_evidence['advice_history'])->toBe('declared_after_run')
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->where('status', '!=', AnalysisStatus::Done)->exists())->toBeFalse();
});
