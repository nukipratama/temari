<?php

declare(strict_types=1);

use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\Run\Plan\SupportedTimeHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function supportedHistoryPoint(User $user, RaceGoal $race, string $date, int $supportedSec, int $sourceM = 5000): void
{
    TrendDailySnapshot::factory()->for($user)->create([
        'snapshot_date' => $date,
        'race_goal_id' => $race->id,
        'supported_time_sec' => $supportedSec,
        'supported_source_distance_m' => $sourceM,
        'supported_source_date' => '2026-08-01',
    ]);
}

it('reads the race\'s supported time within its season up to today, labelling a step only when the source changed', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['goal_time_sec' => 3_000]);
    Season::factory()->for($user)->create(['race_goal_id' => $race->id, 'starts_at' => '2026-08-03']);
    supportedHistoryPoint($user, $race, '2026-08-02', 3_400);
    supportedHistoryPoint($user, $race, '2026-08-03', 3_300);
    supportedHistoryPoint($user, $race, '2026-08-04', 3_250, 10_000);
    supportedHistoryPoint($user, $race, '2026-08-06', 3_100);

    $history = nonNull(app(SupportedTimeHistory::class)->for($user, $race, Carbon::parse('2026-08-05')));

    expect($history['target_time_sec'])->toBe(3_000)
        ->and(array_column($history['points'], 'supported_time_sec'))->toBe([3_300, 3_250])
        ->and(array_column($history['points'], 'new_source'))->toBe([false, true]);
});

it('reads nothing with fewer than two supported days', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();
    supportedHistoryPoint($user, $race, '2026-08-03', 3_300);

    expect(app(SupportedTimeHistory::class)->for($user, $race, Carbon::parse('2026-08-05')))->toBeNull();
});
