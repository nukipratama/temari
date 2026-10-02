<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('rebuilds a week a concurrent writer inserted after the open transaction took its snapshot', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000,
        'moving_time' => 2400,
        'elapsed_time' => 2400,
        'trimp_edwards' => 60.0,
        'start_date_local' => Carbon::today()->subDays(7),
    ]);
    $runWeekEnding = Carbon::today()->subDays(7)->endOfWeek(Carbon::SUNDAY)->toDateString();

    try {
        DB::beginTransaction();
        $before = WeeklySnapshot::query()->where('user_id', $user->id)->count();

        DB::connection('analytics')->table('weekly_snapshots')->insert([
            'user_id' => $user->id,
            'week_ending' => $runWeekEnding,
            'runs' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = app(WeeklyAggregator::class)->rebuildForwardFrom($user, Carbon::today()->subDays(7));
        DB::commit();

        expect($before)->toBe(0)
            ->and($snapshot)->not->toBeNull()
            ->and($snapshot?->runs)->toBe(1)
            ->and((float) $snapshot?->distance_km)->toBe(8.0)
            ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(2)
            ->and(WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', $runWeekEnding)->value('runs'))->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('users')->where('id', $user->id)->delete();
    }

    expect(DB::table('weekly_snapshots')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('activities')->where('user_id', $user->id)->exists())->toBeFalse();
});
