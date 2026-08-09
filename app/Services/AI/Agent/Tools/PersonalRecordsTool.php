<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;

final class PersonalRecordsTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_personal_records';
    }

    public function description(): string
    {
        return 'The personal records this run broke, if any. An empty list means this session did '
            .'NOT break any PR, so never mention a PR at all.';
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
            ])
            ->all();

        return ['personal_records' => $records];
    }
}
