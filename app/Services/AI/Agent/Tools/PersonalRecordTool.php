<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;
use App\Services\Run\Metrics\VdotEstimator;

/**
 * The record being celebrated, and the one it beat.
 */
final class PersonalRecordTool extends NoArgumentTool
{
    public function __construct(
        private readonly PersonalRecord $record,
        private readonly VdotEstimator $vdotEstimator,
    ) {
    }

    public function name(): string
    {
        return 'get_record';
    }

    public function description(): string
    {
        return "The PR you're celebrating: category, its time, when it was set, plus the previous "
            .'PR in the same category and the difference (delta_sec positive = faster now). '
            .'is_strongest_event true means this category is the strongest of all their distances. '
            .'If previous_* is missing, this is the first PR in that category.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $previous = PersonalRecord::query()
            ->where('user_id', $this->record->user_id)
            ->where('category', $this->record->category)
            ->where('id', '<>', $this->record->id)
            ->orderByDesc('set_at')
            ->first();

        $vdot = $this->vdotEstimator->estimate($this->record->user);

        return [
            'category' => $this->record->category->value,
            'value_sec' => $this->record->value_sec,
            'set_at' => $this->record->set_at->toDateString(),
            'previous_value_sec' => $previous?->value_sec,
            'previous_set_at' => $previous?->set_at?->toDateString(),
            'delta_sec' => $previous !== null ? ($previous->value_sec - $this->record->value_sec) : null,
            'vdot' => $vdot['vdot'] ?? null,
            'is_strongest_event' => $vdot !== null && $vdot['source_category'] === $this->record->category->value,
        ];
    }
}
