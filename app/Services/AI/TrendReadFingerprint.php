<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Agent\Tools\PlanAdherenceTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

final readonly class TrendReadFingerprint
{
    public function __construct(private TrainingLoad $trainingLoad)
    {
    }

    public function forUser(User $user, string $range): string
    {
        $totals = new TrendRangeTool($user, $range, $this->trainingLoad)->handle([]);
        $today = Carbon::today();
        $adherence = new PlanAdherenceTool(
            $user,
            $today,
            $today->copy()->subDays(TrendRangeTool::RANGE_DAYS[$range] - 1),
        )->handle([]);

        return MaterialFingerprint::forTrendRead($totals, $adherence);
    }

    public function changed(User $user, string $range): bool
    {
        $row = Analysis::query()
            ->forSubject(AnalysisType::TrendRead->subjectType(), $user->id, AnalysisType::TrendRead, $range)
            ->first();

        if ($row === null || $row->status !== AnalysisStatus::Done) {
            return false;
        }

        return $row->content_fingerprint === null
            || $row->content_fingerprint !== $this->forUser($user, $range);
    }
}
