<?php

declare(strict_types=1);

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\Agent\Tools\MonthRecordsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function monthRecordRun(User $user, string $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

function monthRecord(Activity $activity, PrCategory $category, float $valueSec, string $setAt): void
{
    PersonalRecord::factory()->forActivity($activity)->create([
        'category' => $category,
        'value_sec' => $valueSec,
        'set_at' => Carbon::parse($setAt),
    ]);
}

it('reads the records the month runs set, its last day 23:59 in and the next month first day out', function (): void {
    $user = User::factory()->create();
    monthRecord(monthRecordRun($user, '2026-04-30 23:59:00'), PrCategory::Km1, 240.0, '2026-04-30 23:59:00');
    monthRecord(monthRecordRun($user, '2026-05-01 00:00:00'), PrCategory::Km5, 1402.0, '2026-05-01 00:00:00');
    monthRecord(monthRecordRun($user, '2026-05-31 23:59:00'), PrCategory::Km10, 2985.4, '2026-05-31 23:59:00');
    monthRecord(monthRecordRun($user, '2026-06-01 00:00:00'), PrCategory::Km15, 4500.0, '2026-06-01 00:00:00');
    monthRecord(monthRecordRun(User::factory()->create(), '2026-05-10 07:00:00'), PrCategory::Km5, 1300.0, '2026-05-10 07:00:00');

    $tool = new MonthRecordsTool($user, '2026-05');
    $records = $tool->handle([])['personal_records'];

    expect($tool->name())->toBe('get_month_records')
        ->and($tool->description())->toContain('a run this month set that still stand')
        ->and($tool->description())->toContain('no run this month set a PR')
        ->and($records)->toBe([
            ['category' => '5km', 'value_sec' => 1402.0, 'value_formatted' => '23:22', 'day' => 'Friday', 'date' => '2026-05-01'],
            ['category' => '10km', 'value_sec' => 2985.4, 'value_formatted' => '49:45', 'day' => 'Sunday', 'date' => '2026-05-31'],
        ]);
});

it('reads a month with no records as an empty list', function (): void {
    expect(new MonthRecordsTool(User::factory()->create(), '2026-05')->handle([]))->toBe(['personal_records' => []]);
});
