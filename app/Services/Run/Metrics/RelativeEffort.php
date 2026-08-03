<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\Activity;
use App\Models\ActivityDetail;

/**
 * How hard a run was for the runner *relative to their own recent norm*, using
 * the Edwards TRIMP we already compute ({@see ActivityDetail::$trimp_edwards})
 * against the 28-day baseline from {@see ResolveRunBaselineAction}. This is the "vs biasanya"
 * framing the raw TRIMP tile can't give on its own; it never compares the runner
 * to anyone else.
 *
 * Bands are semantic (not presentation): the UI/narrator maps them onto the
 * Daybreak mood register and Indonesian copy.
 */
class RelativeEffort
{
    public const string WELL_ABOVE = 'well_above';

    public const string ABOVE = 'above';

    public const string TYPICAL = 'typical';

    public const string BELOW = 'below';

    /** Minimum prior runs *with* a TRIMP before we trust the baseline enough to compare. */
    private const int MIN_BASELINE_RUNS = 3;

    public function __construct(private readonly ResolveRunBaselineAction $baseline)
    {
    }

    /**
     * @return array{trimp: float, baseline: int|null, ratio: float|null, band: string|null}|null
     *   Null when the run has no TRIMP (no HR). When the baseline is too thin,
     *   `baseline`/`ratio`/`band` are null but the raw `trimp` is still returned.
     */
    public function forRun(Activity $activity, ActivityDetail $detail): ?array
    {
        $trimp = $detail->trimp_edwards;
        $asOf = $detail->start_date_local;
        if ($trimp === null || $trimp <= 0.0 || $asOf === null) {
            return null;
        }

        $baseline = ($this->baseline)($activity->user_id, $asOf, $activity->id);
        $avgTrimp = $baseline['avg_trimp'] ?? null;

        if ($baseline === null || $avgTrimp === null || $avgTrimp <= 0 || $baseline['trimp_runs'] < self::MIN_BASELINE_RUNS) {
            return ['trimp' => round($trimp, 1), 'baseline' => null, 'ratio' => null, 'band' => null];
        }

        $ratio = round($trimp / $avgTrimp, 2);

        return [
            'trimp' => round($trimp, 1),
            'baseline' => $avgTrimp,
            'ratio' => $ratio,
            'band' => $this->band($ratio),
        ];
    }

    private function band(float $ratio): string
    {
        return match (true) {
            $ratio >= 1.25 => self::WELL_ABOVE,
            $ratio >= 1.10 => self::ABOVE,
            $ratio <= 0.90 => self::BELOW,
            default => self::TYPICAL,
        };
    }
}
