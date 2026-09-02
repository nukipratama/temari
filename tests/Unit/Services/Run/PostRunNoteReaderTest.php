<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\PostRunNoteReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function postRunSpeechFor(Activity $activity, AnalysisStatus $status, ?string $content): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'status' => $status,
        'content' => $content,
    ]);
}

it('returns mood + oneline for a single ready activity', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'blazing']);
    postRunSpeechFor($activity, AnalysisStatus::Done, 'A strong morning run.');

    expect(new PostRunNoteReader()->forActivity($activity->id))
        ->toBe(['oneline' => 'A strong morning run.', 'mood' => 'blazing']);
});

it('returns null for a single activity when the speech is not Done', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'chill']);
    postRunSpeechFor($activity, AnalysisStatus::Pending, 'not ready');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns null for a single activity when the mood is missing', function (): void {
    $activity = Activity::factory()->create();
    postRunSpeechFor($activity, AnalysisStatus::Done, 'ada speech, tanpa mood');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns null for a single activity when the speech content is empty', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'easy']);
    postRunSpeechFor($activity, AnalysisStatus::Done, '');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns an empty array for an empty batch without querying', function (): void {
    expect(new PostRunNoteReader()->forActivities([]))->toBe([]);
});

it('moodsFor returns the persisted mood even when the speech is not ready yet', function (): void {
    $withSpeech = Activity::factory()->create();
    StoryLine::factory()->for($withSpeech)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'blazing']);
    postRunSpeechFor($withSpeech, AnalysisStatus::Done, 'Mantap.');

    // Mood persisted at ingest, but the speech is still pending — moodsFor still
    // surfaces the mood (unlike forActivities, which gates on the speech).
    $pending = Activity::factory()->create();
    StoryLine::factory()->for($pending)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'gassed']);
    postRunSpeechFor($pending, AnalysisStatus::Pending, null);

    $noStoryLine = Activity::factory()->create();

    $moods = new PostRunNoteReader()->moodsFor([$withSpeech->id, $pending->id, $noStoryLine->id]);

    expect($moods)->toBe([
        $withSpeech->id => 'blazing',
        $pending->id => 'gassed',
    ]);
});

it('moodsFor returns an empty array for an empty batch', function (): void {
    expect(new PostRunNoteReader()->moodsFor([]))->toBe([]);
});

it('keys ready notes by activity id and omits unready ones', function (): void {
    $ready = Activity::factory()->create();
    StoryLine::factory()->for($ready)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'blazing']);
    postRunSpeechFor($ready, AnalysisStatus::Done, 'siap');

    $noSpeech = Activity::factory()->create();
    StoryLine::factory()->for($noSpeech)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'chill']);

    $noMood = Activity::factory()->create();
    postRunSpeechFor($noMood, AnalysisStatus::Done, 'tanpa mood');

    $notes = new PostRunNoteReader()->forActivities([$ready->id, $noSpeech->id, $noMood->id]);

    expect($notes)->toBe([$ready->id => ['oneline' => 'siap', 'mood' => 'blazing']]);
});

it('bundles notes and moods from a single story-line read', function (): void {
    $ready = Activity::factory()->create();
    StoryLine::factory()->for($ready)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'blazing']);
    postRunSpeechFor($ready, AnalysisStatus::Done, 'siap');

    $moodOnly = Activity::factory()->create();
    StoryLine::factory()->for($moodOnly)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'gassed']);
    postRunSpeechFor($moodOnly, AnalysisStatus::Pending, null);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $bundle = new PostRunNoteReader()->bundleFor([$ready->id, $moodOnly->id]);

    expect($bundle)->toBe([
        'notes' => [$ready->id => ['oneline' => 'siap', 'mood' => 'blazing']],
        'moods' => [$ready->id => 'blazing', $moodOnly->id => 'gassed'],
    ])->and($queries)->toBe(2);
});

it('bundles empty maps for an empty batch', function (): void {
    expect(new PostRunNoteReader()->bundleFor([]))->toBe(['notes' => [], 'moods' => []]);
});

it('ignores non-post-run story lines and non-Done speech in a batch', function (): void {
    $activity = Activity::factory()->create();
    // Daily-greeting story line carries a mood but is the wrong kind.
    StoryLine::factory()->dailyGreeting()->create(['user_id' => $activity->user_id, 'mood' => 'blazing']);
    postRunSpeechFor($activity, AnalysisStatus::Done, 'siap');

    expect(new PostRunNoteReader()->forActivities([$activity->id]))->toBe([]);
});
