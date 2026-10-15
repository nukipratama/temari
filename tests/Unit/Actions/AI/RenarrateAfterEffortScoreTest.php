<?php

declare(strict_types=1);

use App\Actions\AI\RenarrateAfterEffortScore;
use App\Jobs\AI\RecheckEffortScoreNarrationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
});
afterEach(fn () => Carbon::setTestNow());

/** A narrated run whose speech was stamped before any effort score was logged. */
function effortScoreNarratedRun(array $userAttributes = []): Activity
{
    $activity = Activity::factory()->for(User::factory()->create($userAttributes))->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-08-12 06:00:00', 'perceived_effort' => null]);
    Analysis::factory()->done()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forActivity($activity->fresh()),
    ]);

    return $activity;
}

function speechRowOf(Activity $activity): Analysis
{
    return Analysis::query()->where('subject_id', $activity->id)->where('analysis_type', AnalysisType::PostRunSpeech)->sole();
}

it('re-narrates a run once its first effort score lands', function (): void {
    $activity = effortScoreNarratedRun();
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    expect(speechRowOf($activity)->status)->not->toBe(AnalysisStatus::Done);
});

it('leaves the narration alone when the score only changes', function (): void {
    $activity = effortScoreNarratedRun();
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);
    speechRowOf($activity)->update(['content_fingerprint' => MaterialFingerprint::forActivity($activity->fresh())]);
    $activity->detail->update(['perceived_effort' => 8]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    expect(speechRowOf($activity)->status)->toBe(AnalysisStatus::Done);
});

it('never narrates the demo athlete', function (): void {
    $activity = effortScoreNarratedRun(['is_demo' => true]);
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    expect(speechRowOf($activity)->status)->toBe(AnalysisStatus::Done);
});

it('leaves a speech that was never stamped alone', function (): void {
    $activity = effortScoreNarratedRun();
    speechRowOf($activity)->update(['content_fingerprint' => null]);
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    expect(speechRowOf($activity)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNothingDispatched();
});

it('waits for a narration that has not landed yet instead of requesting another', function (): void {
    $activity = effortScoreNarratedRun();
    speechRowOf($activity)->update(['status' => AnalysisStatus::Pending]);
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    Bus::assertNothingDispatched();
});

it('checks again once a narration still being written has landed', function (): void {
    $activity = effortScoreNarratedRun();
    speechRowOf($activity)->update(['status' => AnalysisStatus::Processing]);
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh());

    Bus::assertDispatched(fn (RecheckEffortScoreNarrationJob $job): bool => $job->activityId === $activity->id
        && $job->delay === (int) config('horizon.defaults.supervisor-ai.timeout'));
});

it('checks again only once', function (): void {
    $activity = effortScoreNarratedRun();
    speechRowOf($activity)->update(['status' => AnalysisStatus::Processing]);
    $activity->detail->update(['perceived_effort' => 6, 'perceived_effort_at' => now()]);

    app(RenarrateAfterEffortScore::class)($activity->fresh(), recheck: false);

    Bus::assertNotDispatched(RecheckEffortScoreNarrationJob::class);
});
