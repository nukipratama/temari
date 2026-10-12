<?php

declare(strict_types=1);

use App\Enums\Mood;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function seedRunWithNote(User $user, int $daysAgo, Mood $mood, string $speech): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays($daysAgo),
        'distance' => 5000.0 + ($daysAgo * 100),
        'trimp_edwards' => 60.0,
    ]);
    StoryLine::factory()->for($activity)->create([
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
        'speech' => null,
    ]);
    Analysis::factory()->done($speech)->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    return $activity;
}

it('attaches notes keyed by activity_id when post-run analyses exist', function (): void {
    $user = User::factory()->create();
    $activity = seedRunWithNote($user, 0, Mood::Easy, 'A strong run');

    $this->actingAs($user)
        ->get('/history', inertiaPartialHeaders($this->actingAs($user), '/history', 'History', 'notes'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'History')
        ->assertJsonPath("props.notes.{$activity->id}.oneline", 'A strong run')
        ->assertJsonPath("props.notes.{$activity->id}.mood", Mood::Easy->value);
});
