<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PlanAdaptation;

/**
 * Why this week's plan looks the way it does: the periodizer's own verdict
 * ({@see \App\Services\Run\Plan\PlanAdapter}), already reduced to rule-based
 * headline/detail text. The model's job is to turn this into warmer prose in
 * Temari's voice, not to re-derive the reason itself.
 */
final class PlanWeekTool extends NoArgumentTool
{
    public function __construct(private readonly PlanAdaptation $adaptation)
    {
    }

    public function name(): string
    {
        return 'get_week_adaptation';
    }

    public function description(): string
    {
        return "This week's periodizer verdict: the reason it adapted the plan (or didn't), "
            .'whether it turned the week into a deload, how the quality-session count moved, and '
            .'last week\'s adherence percentage that verdict was based on.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'week_start' => $this->adaptation->week_start->toDateString(),
            'reason' => $this->adaptation->reason->value,
            'headline' => $this->adaptation->reason->headline(),
            'detail' => $this->adaptation->reason->detail($this->adaptation->adherence_pct),
            'deload' => $this->adaptation->deload,
            'quality_delta' => $this->adaptation->quality_delta,
            'adherence_pct' => $this->adaptation->adherence_pct,
        ];
    }
}
