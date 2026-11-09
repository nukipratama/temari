<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;
use App\Models\WeeklySnapshot;

/**
 * The personal records the week's runs set, the week-scoped counterpart to {@see PersonalRecordsTool}.
 */
final class WeekRecordsTool extends NoArgumentTool
{
    public function __construct(private readonly WeeklySnapshot $snapshot)
    {
    }

    public function name(): string
    {
        return 'get_week_records';
    }

    public function description(): string
    {
        return 'The personal records a run this week set that still stand, each with category (a '
            .'distance such as 5km or a best effort such as best_20min), the day and date of the run '
            .'that set it, value_formatted (h:mm:ss or mm:ss, the only form to quote) and value_sec '
            .'(raw seconds, for judging size, never for quoting). An empty list means no run this '
            .'week set a PR, so never mention one.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $records = PersonalRecord::query()
            ->where('user_id', $this->snapshot->user_id)
            ->whereIn('activity_id', WeekRunsTool::detailsInWeek($this->snapshot)->select('activity_details.activity_id'))
            ->orderBy('set_at')
            ->get(['category', 'value_sec', 'set_at'])
            ->map(fn (PersonalRecord $record): array => [
                ...PersonalRecordsTool::entry($record),
                'day' => $record->set_at->format('l'),
                'date' => $record->set_at->toDateString(),
            ])
            ->all();

        return ['personal_records' => $records];
    }
}
