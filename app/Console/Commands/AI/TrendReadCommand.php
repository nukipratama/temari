<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:trend-read {range : One of AnalysisType::TREND_READ_RANGES (7d/30d/90d/12mo)}')]
#[Description('Dispatch the Trends tab narration for one range (7d/30d/90d/12mo), one cadence per range — see routes/console.php')]
class TrendReadCommand extends Command
{
    public function handle(AnalysisService $service, RecentlyActiveUsers $activeUsers, TrainingLoad $trainingLoad): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $range = (string) $this->argument('range');
        if (! in_array($range, AnalysisType::TREND_READ_RANGES, true)) {
            $this->error('range must be one of: '.implode(', ', AnalysisType::TREND_READ_RANGES));

            return self::FAILURE;
        }

        $users = $activeUsers();

        foreach ($users as $user) {
            $service->request(
                subjectOrType: AnalysisType::TrendRead->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::TrendRead,
                discriminator: $range,
                invalidate: $this->materialChanged($user, $range, $trainingLoad),
            );
        }

        $this->info("Dispatched trend read ({$range}) for {$users->count()} active users.");

        return self::SUCCESS;
    }

    /**
     * Whether this range's numbers have moved since the stored read was
     * generated, so the cadence re-narrates only a range that changed rather
     * than every active athlete on every cron tick. A row with no stamped
     * fingerprint (every row that existed before this gate shipped) counts as
     * changed, matching {@see \App\Services\AI\PlanNarrationRequester}'s
     * day-voice rule — the inverse of
     * {@see \App\Listeners\DispatchPostRunAnalysis::materialRefreshDue()} —
     * so each existing Done read refreshes once on its next scheduled run and
     * the fingerprint gate takes over from there.
     */
    private function materialChanged(User $user, string $range, TrainingLoad $trainingLoad): bool
    {
        $row = Analysis::query()
            ->forSubject(AnalysisType::TrendRead->subjectType(), $user->id, AnalysisType::TrendRead, $range)
            ->first();

        if ($row === null || $row->status !== AnalysisStatus::Done) {
            return false;
        }

        if ($row->content_fingerprint === null) {
            return true;
        }

        $totals = new TrendRangeTool($user, $range, $trainingLoad)->handle([]);

        return $row->content_fingerprint !== MaterialFingerprint::forTrendRead($totals);
    }
}
