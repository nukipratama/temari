<?php

declare(strict_types=1);

use App\Actions\AI\SettleEarlyNarrationAction;
use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, older: Activity, newer: Activity}
 */
function earlyPassUser(): array
{
    $user = User::factory()->create(['history_replay_due_at' => Carbon::now()]);

    $older = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::now()->subDays(2)]);
    $newer = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($newer)->create(['start_date_local' => Carbon::now()->subDay()]);

    foreach ([$older, $newer] as $activity) {
        $card = RunCard::factory()->create(['activity_id' => $activity->id]);
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::PostRunSpeech,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::RunInsight,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
        Analysis::factory()->done()->create([
            'subject_type' => RunCard::class,
            'subject_id' => $card->id,
            'analysis_type' => AnalysisType::CardFlavor,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
    }

    Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'narrated_early_at' => Carbon::now(),
    ]);
    Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::ProfileVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::ProfileVoice,
        'discriminator' => AnalysisType::currentIsoWeek(),
        'narrated_early_at' => Carbon::now(),
    ]);

    return ['user' => $user, 'older' => $older, 'newer' => $newer];
}

it('does nothing when the user has no replay owed', function (): void {
    $user = User::factory()->create();
    $this->mock(PersonalRecords::class)->shouldNotReceive('rebuildForUser');
    $this->mock(RecomputeCardClaimsAction::class)->shouldNotReceive('__invoke');

    app(SettleEarlyNarrationAction::class)($user);

    expect($user->fresh()->history_replay_due_at)->toBeNull();
});

it('rebuilds PRs and replays cards even when nothing was ever marked for narration', function (): void {
    // PR detection can be deferred by ActivityPipeline (stamping the user
    // flag) even when the narration for that same run finishes after the
    // drain already landed — so it was never marked `narrated_early_at`.
    Bus::fake();
    $user = User::factory()->create(['history_replay_due_at' => Carbon::now()]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    expect($user->fresh()->history_replay_due_at)->toBeNull();
    Bus::assertNothingDispatched();
});

it('rebuilds PRs, replays cards, and regenerates every early-marked row exactly once', function (): void {
    Bus::fake();
    ['user' => $user, 'older' => $older, 'newer' => $newer] = earlyPassUser();

    $this->mock(PersonalRecords::class)
        ->shouldReceive('rebuildForUser')->once()
        ->with(Mockery::on(fn (User $u): bool => $u->id === $user->id));
    $this->mock(RecomputeCardClaimsAction::class)
        ->shouldReceive('__invoke')->once()
        ->with(Mockery::on(fn (User $u): bool => $u->id === $user->id))
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    expect($user->fresh()->history_replay_due_at)->toBeNull();
    expect(Analysis::query()->whereNotNull('narrated_early_at')->count())->toBe(0);

    // Only the earliest activity's group is dispatched directly; its own
    // chain-advance (untested here, covered by AnalyzeActivityJob's own
    // suite) is what would carry $newer's group forward in production.
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id,
    );
    Bus::assertNotDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $newer->id,
    );
    Bus::assertDispatched(AnalyzeCardFlavorJob::class, 2);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeProfileVoiceJob::class);

    expect(Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $older->id)
        ->where('status', AnalysisStatus::Queued)
        ->count())->toBe(2)
        ->and(Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $newer->id)
            ->where('status', AnalysisStatus::Pending)
            ->count())->toBe(2);
});

it('bills nothing more on a second completion signal', function (): void {
    Bus::fake();
    ['user' => $user] = earlyPassUser();

    $personalRecords = $this->mock(PersonalRecords::class);
    $personalRecords->shouldReceive('rebuildForUser')->once();
    $recomputeCardClaims = $this->mock(RecomputeCardClaimsAction::class);
    $recomputeCardClaims->shouldReceive('__invoke')->once()->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    $action = app(SettleEarlyNarrationAction::class);
    $action($user->fresh());
    Bus::fake();
    $action($user->fresh());

    Bus::assertNothingDispatched();
});
