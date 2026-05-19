<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Temari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function seedRunWithNote(User $user, int $daysAgo, string $mood, string $speech): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays($daysAgo),
        'distance' => 5000.0 + ($daysAgo * 100),
        'trimp_edwards' => 60.0,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
        'speech' => null,
        'sigil_pattern' => 'dddd',
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
    $activity = seedRunWithNote($user, 0, Temari::MOOD_BOUNCY, 'Run yang mantap');

    $this->actingAs($user)->get('/aktivitas')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->where("notes.{$activity->id}.oneline", 'Run yang mantap')
            ->where("notes.{$activity->id}.mood", Temari::MOOD_BOUNCY));
});

it('omits notes when there are no post-run StoryLines', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'trimp_edwards' => 60.0,
    ]);

    $this->actingAs($user)->get('/aktivitas')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('notes', []));
});

it('does not leak notes across users', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    seedRunWithNote($a, 0, Temari::MOOD_BOUNCY, 'a-only line');

    $this->actingAs($b)->get('/aktivitas')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('notes', []));
});
