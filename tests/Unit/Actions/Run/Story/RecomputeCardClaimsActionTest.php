<?php

declare(strict_types=1);

use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\Temari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function claimedRun(User $user, string $date, int $secPerKm, bool $prSet, string $mood): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $perKm = [];
    for ($k = 1; $k <= 5; $k++) {
        $perKm[] = ['km' => $k, 'pace' => sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60), 'elapsed_sec' => $secPerKm, 'distance_m' => 1000];
    }
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($date),
        'distance' => 5000,
        'stream_summary' => ['per_km' => $perKm],
        'weather_temp_c' => 25,
    ]);
    RunCard::factory()->create(['activity_id' => $activity->id, 'pr_set' => $prSet]);
    StoryLine::factory()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
    ]);

    return $activity;
}

it('clears a phantom PR and its frozen mood, and earns the flag for a run that really set one', function (): void {
    $user = User::factory()->create();
    $genuine = claimedRun($user, '2025-11-26 06:00:00', 360, false, Temari::MOOD_ADEM);
    $phantom = claimedRun($user, '2026-09-17 17:39:00', 434, true, Temari::MOOD_NYALA);

    $result = app(RecomputeCardClaimsAction::class)($user);

    expect($result)->toBe(['cleared' => [$phantom->id], 'earned' => [$genuine->id], 'moods' => 2])
        ->and($phantom->runCard()->value('pr_set'))->toBeFalse()
        ->and($phantom->postRunStoryLine()->value('mood'))->not->toBe(Temari::MOOD_NYALA)
        ->and($genuine->runCard()->value('pr_set'))->toBeTrue()
        ->and($genuine->postRunStoryLine()->value('mood'))->toBe(Temari::MOOD_NYALA);
});

it('keeps a PR earned on its day after a later run beats it, and changes nothing on a second pass', function (): void {
    $user = User::factory()->create();
    $earned = claimedRun($user, '2026-01-01 06:00:00', 360, true, Temari::MOOD_NYALA);
    $better = claimedRun($user, '2026-03-01 06:00:00', 330, true, Temari::MOOD_NYALA);

    $action = app(RecomputeCardClaimsAction::class);

    expect($action($user))->toBe(['cleared' => [], 'earned' => [], 'moods' => 0])
        ->and($earned->runCard()->value('pr_set'))->toBeTrue()
        ->and($better->runCard()->value('pr_set'))->toBeTrue();
});
