<?php

declare(strict_types=1);

use App\Jobs\AI\RecheckEffortScoreNarrationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

it('re-narrates a run whose speech landed without the effort score saved while it was being written', function (): void {
    $activity = Activity::factory()->for(User::factory()->create())->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-08-12 06:00:00', 'perceived_effort' => 6]);
    $speech = Analysis::factory()->done()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'content_fingerprint' => 'stamped-before-the-score',
    ]);

    app()->call([new RecheckEffortScoreNarrationJob($activity->id), 'handle']);

    expect($speech->fresh()->status)->not->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(RecheckEffortScoreNarrationJob::class);
});

it('does nothing for a run that no longer exists', function (): void {
    app()->call([new RecheckEffortScoreNarrationJob(999_999), 'handle']);

    Bus::assertNothingDispatched();
});
