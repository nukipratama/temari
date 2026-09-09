<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;
use App\Services\Run\Metrics\DurationFormatter;

final class PersonalRecordsTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_personal_records';
    }

    public function description(): string
    {
        return 'The personal records this run broke, if any, each with value_formatted (h:mm:ss or '
            .'mm:ss, the only form to quote) and value_sec (raw seconds, for judging size, never for '
            .'quoting). An empty list means this session did NOT break any PR, so never mention a PR '
            .'at all.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $records = PersonalRecord::query()
            ->where('activity_id', $this->activity->id)
            ->get(['category', 'value_sec'])
            ->map(fn (PersonalRecord $record): array => [
                'category' => $record->category->value,
                'value_sec' => $record->value_sec,
                'value_formatted' => DurationFormatter::hms((int) round($record->value_sec)),
            ])
            ->all();

        return ['personal_records' => $records];
    }
}
