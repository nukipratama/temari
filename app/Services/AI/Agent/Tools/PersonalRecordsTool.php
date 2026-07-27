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
        return 'Personal record yang dipecahkan oleh lari ini, kalau ada. Daftar kosong berarti sesi '
            .'ini TIDAK memecahkan PR apa pun, jadi jangan sebut PR sama sekali.';
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
