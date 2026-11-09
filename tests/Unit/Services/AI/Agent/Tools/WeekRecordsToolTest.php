<?php

declare(strict_types=1);

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\Agent\Tools\WeekRecordsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function weekRecordRun(User $user, string $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

function weekRecord(Activity $activity, PrCategory $category, float $valueSec, string $setAt): void
{
    PersonalRecord::factory()->forActivity($activity)->create([
        'category' => $category,
        'value_sec' => $valueSec,
        'set_at' => Carbon::parse($setAt),
    ]);
}

it('reads the records the week runs set, oldest first, and none set either side of it', function (): void {
    $user = User::factory()->create();
    weekRecord(weekRecordRun($user, '2026-05-24 23:59:00'), PrCategory::Km1, 240.0, '2026-05-24 23:59:00');
    weekRecord(weekRecordRun($user, '2026-05-30 08:00:00'), PrCategory::Km10, 2985.4, '2026-05-30 08:00:00');
    $tuesday = weekRecordRun($user, '2026-05-26 07:00:00');
    weekRecord($tuesday, PrCategory::Km5, 1402.0, '2026-05-26 07:00:00');
    weekRecord($tuesday, PrCategory::Best20Min, 281.0, '2026-05-26 07:00:00');
    weekRecord(weekRecordRun($user, '2026-06-01 00:00:00'), PrCategory::Km15, 4500.0, '2026-06-01 00:00:00');
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31']);

    $records = new WeekRecordsTool($snapshot)->handle([])['personal_records'];

    expect($records)->toEqualCanonicalizing([
        ['category' => '5km', 'value_sec' => 1402.0, 'value_formatted' => '23:22', 'day' => 'Tuesday', 'date' => '2026-05-26'],
        ['category' => 'best_20min', 'value_sec' => 281.0, 'value_formatted' => '4:41', 'day' => 'Tuesday', 'date' => '2026-05-26'],
        ['category' => '10km', 'value_sec' => 2985.4, 'value_formatted' => '49:45', 'day' => 'Saturday', 'date' => '2026-05-30'],
    ])
        ->and(array_column(arrayOf($records), 'date'))->toBe(['2026-05-26', '2026-05-26', '2026-05-30']);
});

it('reads a week with no records as an empty list, and never another athlete record', function (): void {
    $user = User::factory()->create();
    weekRecordRun($user, '2026-05-27 07:00:00');
    weekRecord(weekRecordRun(User::factory()->create(), '2026-05-27 07:00:00'), PrCategory::Km5, 1500.0, '2026-05-27 07:00:00');
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31']);

    expect(new WeekRecordsTool($snapshot)->handle([]))->toBe(['personal_records' => []]);
});
