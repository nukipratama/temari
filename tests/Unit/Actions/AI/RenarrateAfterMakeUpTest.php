<?php

declare(strict_types=1);

use App\Actions\AI\RenarrateAfterMakeUp;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
});
afterEach(fn () => Carbon::setTestNow());

/**
 * A 5 km run on `$targetDate` with its narration and card already landed.
 *
 * @return array{User, Carbon, Carbon, Activity}
 */
function makeUpNarrationFixture(string $vacatedDate, string $targetDate, array $userAttributes = []): array
{
    $user = User::factory()->create($userAttributes);
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

    return [$user, Carbon::parse($vacatedDate), Carbon::parse($targetDate), $activity];
}

it('invalidates the made-up day\'s run narration, and rebriefs when the make-up lands today', function (): void {
    [$user, $vacated, $target, $activity] = makeUpNarrationFixture('2026-08-11', '2026-08-12');

    app(RenarrateAfterMakeUp::class)($user, $vacated, $target, Carbon::today());

    expect(Analysis::query()->where('analysis_type', AnalysisType::PostRunSpeech)->sole()->status)->not->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::CardFlavor)->sole()->status)->not->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->not->toBe(AnalysisStatus::Done);
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('re-narrates the runs on the day the make-up emptied, never for the demo athlete', function (bool $demo): void {
    [$user, $vacated, $target] = makeUpNarrationFixture('2026-08-11', '2026-08-12', ['is_demo' => $demo]);
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

    app(RenarrateAfterMakeUp::class)($user, $vacated, $target, Carbon::today());

    expect($speech->fresh()->status === AnalysisStatus::Done)->toBe($demo)
        ->and($flavor->fresh()->status === AnalysisStatus::Done)->toBe($demo);
    if ($demo) {
        Bus::assertNothingDispatched();
    } else {
        Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $emptiedDayRun->id);
        Bus::assertDispatched(fn (AnalyzeCardFlavorJob $job): bool => $job->analysisId === $flavor->id);
    }
})->with(['athlete' => [false], 'demo' => [true]]);

it('leaves today\'s briefing alone when the make-up lands on an earlier day', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00');
    [$user, $vacated, $target] = makeUpNarrationFixture('2026-08-11', '2026-08-12');

    app(RenarrateAfterMakeUp::class)($user, $vacated, $target, Carbon::today());

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->toBe(AnalysisStatus::Done);
});

it('rebriefs when today\'s own session is the one made up onto an earlier day', function (): void {
    [$user, $vacated, $target] = makeUpNarrationFixture('2026-08-12', '2026-08-11');

    app(RenarrateAfterMakeUp::class)($user, $vacated, $target, Carbon::today());

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->sole()->status)->not->toBe(AnalysisStatus::Done);
});

it('merges a burst of back-and-forth make-up moves into one delayed job per run group, card flavor and briefing', function (): void {
    [$user, $vacated, $target, $activity] = makeUpNarrationFixture('2026-08-11', '2026-08-12');
    $renarrate = app(RenarrateAfterMakeUp::class);

    $renarrate($user, $vacated, $target, Carbon::today());
    $renarrate($user, $target, $vacated, Carbon::today());
    $renarrate($user, $vacated, $target, Carbon::today());

    $delayed = fn (object $job): bool => $job->delay === AnalysisService::PLAN_EDIT_DELAY_SECONDS;
    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id && $delayed($job));
    Bus::assertDispatchedTimes(AnalyzeCardFlavorJob::class, 1);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class, $delayed);
    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class, $delayed);
});
