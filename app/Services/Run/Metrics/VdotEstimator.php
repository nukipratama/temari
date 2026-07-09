<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;

/**
 * Daniels' VDOT formula (1998 tables):
 *   v     = distance_m / time_min                                          (m/min)
 *   VO2   = -4.60 + 0.182258·v + 0.000104·v²                              (ml/kg/min)
 *   pmax  = 0.80 + 0.1894393·e^(-0.012778·t) + 0.2989558·e^(-0.1932605·t) (fraction of VO2max sustainable for t min)
 *   VDOT  = VO2 / pmax
 * Skipping pmax underestimates marathon VDOT by ~10 points.
 */
class VdotEstimator
{
    // Coefficients of the VO2 = c + b·v + a·v² relationship (v in m/min),
    // shared with {@see \App\Services\Run\Metrics\TrainingPaceCalculator}, which
    // solves this same quadratic for v given a target VO2.
    public const float VO2_COEFFICIENT_A = 0.000104;

    public const float VO2_COEFFICIENT_B = 0.182258;

    public const float VO2_COEFFICIENT_C = -4.60;

    /**
     * @return array{vdot: float, source_category: string}|null
     */
    public function estimate(User $user): ?array
    {
        $eligibleValues = array_map(static fn (PrCategory $c): string => $c->value, PrCategory::distances());

        $prs = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->whereIn('category', $eligibleValues)
            ->get();

        $best = null;
        $bestVdot = null;

        foreach ($prs as $pr) {
            $distance = $pr->category->distanceMeters();
            if ($distance === null) {
                continue;
            }
            $vdot = $this->vdotFromTimeAndDistance($pr->value_sec, $distance);
            if ($vdot === null) {
                continue;
            }
            // Daniels' formula is distance-normalized; max VDOT wins.
            if ($bestVdot === null || $vdot > $bestVdot) {
                $bestVdot = $vdot;
                $best = $pr->category;
            }
        }

        if ($bestVdot === null || $best === null) {
            return null;
        }

        return ['vdot' => round($bestVdot, 1), 'source_category' => $best->value];
    }

    public function vdotFromTimeAndDistance(float $elapsedSec, float $distanceMeters): ?float
    {
        if ($elapsedSec <= 0 || $distanceMeters <= 0) {
            return null;
        }
        $timeMin = $elapsedSec / 60.0;
        $velocity = $distanceMeters / $timeMin; // m/min

        $vo2 = self::VO2_COEFFICIENT_C + self::VO2_COEFFICIENT_B * $velocity + self::VO2_COEFFICIENT_A * $velocity * $velocity;

        // pmax is mathematically always > 0.8 (both exponential terms are positive),
        // so no defensive divide-by-zero check is needed here.
        $pmax = 0.80
            + 0.1894393 * exp(-0.012778 * $timeMin)
            + 0.2989558 * exp(-0.1932605 * $timeMin);

        return $vo2 > 0 ? $vo2 / $pmax : null;
    }
}
