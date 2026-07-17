<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\PostRunNoteReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'nyala']);
    postRunSpeechFor($activity, AnalysisStatus::Done, 'Lari pagi yang mantap.');

    expect(new PostRunNoteReader()->forActivity($activity->id))
        ->toBe(['oneline' => 'Lari pagi yang mantap.', 'mood' => 'nyala']);
});

it('returns null for a single activity when the speech is not Done', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'adem']);
    postRunSpeechFor($activity, AnalysisStatus::Pending, 'belum siap');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns null for a single activity when the mood is missing', function (): void {
    $activity = Activity::factory()->create();
    postRunSpeechFor($activity, AnalysisStatus::Done, 'ada speech, tanpa mood');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns null for a single activity when the speech content is empty', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'enteng']);
    postRunSpeechFor($activity, AnalysisStatus::Done, '');

    expect(new PostRunNoteReader()->forActivity($activity->id))->toBeNull();
});

it('returns an empty array for an empty batch without querying', function (): void {
    expect(new PostRunNoteReader()->forActivities([]))->toBe([]);
});

it('moodsFor returns the persisted mood even when the speech is not ready yet', function (): void {
    $withSpeech = Activity::factory()->create();
    StoryLine::factory()->for($withSpeech)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'nyala']);
    postRunSpeechFor($withSpeech, AnalysisStatus::Done, 'Mantap.');

    // Mood persisted at ingest, but the speech is still pending — moodsFor still
    // surfaces the mood (unlike forActivities, which gates on the speech).
    $pending = Activity::factory()->create();
    StoryLine::factory()->for($pending)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'lemes']);
    postRunSpeechFor($pending, AnalysisStatus::Pending, null);

    $noStoryLine = Activity::factory()->create();

    $moods = new PostRunNoteReader()->moodsFor([$withSpeech->id, $pending->id, $noStoryLine->id]);

    expect($moods)->toBe([
        $withSpeech->id => 'nyala',
        $pending->id => 'lemes',
    ]);
});

it('moodsFor returns an empty array for an empty batch', function (): void {
    expect(new PostRunNoteReader()->moodsFor([]))->toBe([]);
});

it('keys ready notes by activity id and omits unready ones', function (): void {
    $ready = Activity::factory()->create();
    StoryLine::factory()->for($ready)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'nyala']);
    postRunSpeechFor($ready, AnalysisStatus::Done, 'siap');

    $noSpeech = Activity::factory()->create();
    StoryLine::factory()->for($noSpeech)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'adem']);

    $noMood = Activity::factory()->create();
    postRunSpeechFor($noMood, AnalysisStatus::Done, 'tanpa mood');

    $notes = new PostRunNoteReader()->forActivities([$ready->id, $noSpeech->id, $noMood->id]);

    expect($notes)->toBe([$ready->id => ['oneline' => 'siap', 'mood' => 'nyala']]);
});

it('ignores non-post-run story lines and non-Done speech in a batch', function (): void {
    $activity = Activity::factory()->create();
    // Daily-greeting story line carries a mood but is the wrong kind.
    StoryLine::factory()->dailyGreeting()->create(['user_id' => $activity->user_id, 'mood' => 'nyala']);
    postRunSpeechFor($activity, AnalysisStatus::Done, 'siap');

    expect(new PostRunNoteReader()->forActivities([$activity->id]))->toBe([]);
});

it('reads today\'s post-run speech straight from the story line for a user', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->setTime(7, 0)]);
    StoryLine::factory()->for($activity)->create([
        'kind' => StoryLine::KIND_POST_RUN,
        'speech' => 'Quote hari ini.',
    ]);

    expect(new PostRunNoteReader()->speechForToday($activity->user_id))->toBe('Quote hari ini.');
});

it('returns the newest story line speech when several exist for today', function (): void {
    $user = User::factory()->create();

    $older = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::today()->setTime(6, 0)]);
    StoryLine::factory()->for($older)->create(['kind' => StoryLine::KIND_POST_RUN, 'speech' => 'lama']);

    $newer = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($newer)->create(['start_date_local' => Carbon::today()->setTime(18, 0)]);
    StoryLine::factory()->for($newer)->create(['kind' => StoryLine::KIND_POST_RUN, 'speech' => 'baru']);

    expect(new PostRunNoteReader()->speechForToday($user->id))->toBe('baru');
});

it('returns null today-speech when the run is on another day', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()->setTime(7, 0)]);
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'speech' => 'kemarin']);

    expect(new PostRunNoteReader()->speechForToday($activity->user_id))->toBeNull();
});
