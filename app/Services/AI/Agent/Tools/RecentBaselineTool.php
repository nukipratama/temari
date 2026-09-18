<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\User;
use App\Services\Run\Metrics\DecouplingBands;
use Illuminate\Support\Carbon;

final class RecentBaselineTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly ResolveRunBaselineAction $baseline,
        /** Excluded from its own baseline when the caller is narrating that run. */
        private readonly ?int $excludeActivityId = null,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_recent_baseline';
    }

    public function description(): string
    {
        return "The user's average over the last 28 days (pace, HR, decoupling). Call this when you "
            .'want to say something is faster/slower/harder than usual. avg_decoupling carries its '
            .'own relation (up/down/flat) alongside the pct -- there is no sign to read yourself. If '
            .'recent_baseline_28d is missing, their history\'s still too thin.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $baseline = ($this->baseline)($this->user->id, $this->asOf, $this->excludeActivityId);

        if ($baseline !== null) {
            $avgDecouplingPct = $baseline['avg_decoupling_pct'];
            unset($baseline['avg_decoupling_pct']);
            $baseline['avg_decoupling'] = $avgDecouplingPct === null ? null : [
                'pct' => abs($avgDecouplingPct),
                'relation' => DecouplingBands::relationFor($avgDecouplingPct),
            ];
        }

        return ['recent_baseline_28d' => $baseline];
    }
}
