<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RekorController extends Controller
{
    public function __construct(private readonly ProgressionSeriesBuilder $progressionSeriesBuilder)
    {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->with([
                'activity:id',
                'activity.detail:id,activity_id,name,distance,moving_time,location_name,weather_temp_c,weather_humidity_pct,splits_metric',
            ])
            ->get();

        $analyses = Analysis::query()
            ->where('subject_type', PersonalRecord::class)
            ->where('analysis_type', AnalysisType::PrContext)
            ->whereIn('subject_id', $personalRecords->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        $payload = $personalRecords->map(fn (PersonalRecord $row): array => [
            ...$row->toArray(),
            'context_analysis' => Analysis::toPayload(
                $analyses->get($row->id),
                AnalysisType::PrContext,
                PersonalRecord::class,
                $row->id,
            ),
        ])->all();

        $featured = $this->pickFeaturedPr($personalRecords);

        return Inertia::render('Koleksi/Rekor', [
            'personalRecords' => $payload,
            'featuredExtras' => $this->featuredExtras($featured),
            'progressionSeries' => $featured !== null
                ? $this->progressionSeriesBuilder->build($user, $featured, $this->milestoneFor($featured)['target_sec'])
                : null,
        ]);
    }

    /**
     * Pick the PR to feature in the hero scoreboard: highest distance with
     * a PR, falling back to first record.
     *
     * @param  Collection<int, PersonalRecord>  $records
     */
    private function pickFeaturedPr(Collection $records): ?PersonalRecord
    {
        $best = null;
        $bestRank = -1.0;
        foreach ($records as $pr) {
            $target = $pr->category->distanceMeters();
            if ($target === null) {
                continue;
            }
            if ($target > $bestRank) {
                $best = $pr;
                $bestRank = $target;
            }
        }

        return $best ?? $records->first();
    }

    /**
     * Splits + location + milestone target for the hero scoreboard.
     *
     * @return array{pr_id:int,splits_pace_sec:array<int,int>,location_name:string|null,weather_temp_c:int|null,weather_humidity_pct:int|null,target_sec:int|null,delta_sec:int|null}|null
     */
    private function featuredExtras(?PersonalRecord $pr): ?array
    {
        if ($pr === null) {
            return null;
        }

        /** @var ActivityDetail|null $detail */
        $detail = $pr->activity?->detail;

        return [
            'pr_id' => $pr->id,
            'splits_pace_sec' => $this->splitsPaceSec($detail?->splits_metric),
            'location_name' => $detail?->location_name,
            'weather_temp_c' => $detail?->weather_temp_c,
            'weather_humidity_pct' => $detail?->weather_humidity_pct,
            ...$this->milestoneFor($pr),
        ];
    }

    /**
     * Convert Strava-shaped splits_metric (per-km segments with
     * distance + moving_time) into a list of pace-seconds-per-km.
     *
     * @param  array<int, array<string, mixed>>|null  $splits
     * @return array<int, int>
     */
    private function splitsPaceSec(?array $splits): array
    {
        if ($splits === null) {
            return [];
        }

        $out = [];
        foreach ($splits as $row) {
            $distance = isset($row['distance']) ? (float) $row['distance'] : 0.0;
            $time = isset($row['moving_time']) ? (float) $row['moving_time'] : 0.0;
            $paceSecPerKm = PaceCalculator::secPerKm($distance, $time);
            if ($paceSecPerKm === null) {
                continue;
            }
            $out[] = (int) round($paceSecPerKm);
        }

        return $out;
    }

    /**
     * "Sub-{next round minute} di {category}" heuristic. For 1:02:27 →
     * target Sub-1:00:00, delta 2:27.
     *
     * @return array{target_sec:int|null, delta_sec:int|null}
     */
    private function milestoneFor(PersonalRecord $pr): array
    {
        if (! $pr->category->isDistance()) {
            return ['target_sec' => null, 'delta_sec' => null];
        }
        $current = (int) $pr->value_sec;
        if ($current <= 0) {
            return ['target_sec' => null, 'delta_sec' => null];
        }
        // Round down to the next round-minute milestone strictly below current.
        // 3747 (1:02:27) → 3600 (1:00:00). 1751 (29:11) → 1740 (29:00).
        $target = $this->roundedTargetSec($current);

        return [
            'target_sec' => $target,
            'delta_sec' => $current - $target,
        ];
    }

    private function roundedTargetSec(int $current): int
    {
        if ($current > 3600) {
            // Hour-scale: drop to next-lower 5-min increment (e.g., 1:02 → 1:00).
            return (intdiv($current - 60, 300)) * 300;
        }
        if ($current > 600) {
            // 10+ min: round down to next minute.
            return (intdiv($current - 1, 60)) * 60;
        }
        // Sub-10-min: round down to next 15s.
        return (intdiv($current - 1, 15)) * 15;
    }
}
