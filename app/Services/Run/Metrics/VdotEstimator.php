<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * A personal record is only ever replaced by a faster one, so it improves but
     * never ages out on its own. Unbounded, one hard effort from years ago keeps
     * a permanent veto over every prescribed pace.
     */
    public const int RECENT_MONTHS = 12;

    /**
     * A record is a floor on what the athlete could do on its own date, never a
     * ceiling on what they can do now. Taking one minimum across every distance
     * and every date conflates the two: a hard long effort from months ago
     * outvotes a recent short one and holds quality paces below what the athlete
     * demonstrably runs in training. Quality work therefore reads a second
     * anchor, restricted to recent short-distance evidence.
     */
    public const int QUALITY_MONTHS = 3;

    public const float QUALITY_MAX_METERS = 10_000.0;

    /**
     * `vdot` anchors endurance work and takes the minimum across every category
     * in the window, so an easy or long pace never outruns a proven distance.
     * `quality_vdot` anchors threshold and interval work. It reads the same
     * minimum over a narrower slice, and is never below `vdot`, that slice being
     * a subset of this one.
     *
     * @return array{vdot: float, quality_vdot: float, source_category: string, set_at: Carbon, stale: bool, quality_source: array{source_category: string, set_at: Carbon}|null}|null
     */
    public function estimate(User $user, ?Carbon $asOf = null): ?array
    {
        $eligibleValues = array_map(static fn (PrCategory $c): string => $c->value, PrCategory::distances());

        $prs = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->whereIn('category', $eligibleValues)
            ->get();

        $now = $asOf ?? Carbon::now();
        $cutoff = $now->copy()->subMonths(self::RECENT_MONTHS);
        $qualityCutoff = $now->copy()->subMonths(self::QUALITY_MONTHS);

        $result = $this->lowestVdot($prs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->greaterThanOrEqualTo($cutoff),
        ));
        $stale = $result === null;
        $result ??= $this->lowestVdot($prs);

        if ($result === null) {
            return null;
        }

        $quality = $this->lowestVdot($prs->filter(
            static fn (PersonalRecord $pr): bool => $pr->set_at->greaterThanOrEqualTo($qualityCutoff)
                && ($pr->category->distanceMeters() ?? INF) <= self::QUALITY_MAX_METERS,
        ));

        $split = $quality !== null && $quality['vdot'] > $result['vdot'];

        return [
            ...$result,
            'quality_vdot' => $quality['vdot'] ?? $result['vdot'],
            'quality_source' => $split ? [
                'source_category' => $quality['source_category'],
                'set_at' => $quality['set_at'],
            ] : null,
            'stale' => $stale,
        ];
    }

    /**
     * @param  Collection<int, PersonalRecord>  $prs
     * @return array{vdot: float, source_category: string, set_at: Carbon}|null
     */
    private function lowestVdot(Collection $prs): ?array
    {
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
            // Daniels' formula is distance-normalized in theory, but a runner
            // who is disproportionately fast over a short distance (anaerobic
            // speed, not aerobic endurance) makes a max-across-distances VDOT
            // prescribe paces faster than any real PR at longer distances —
            // e.g. a marathon "target" pace quicker than the athlete's actual
            // marathon PR. Same asymmetric-caution stance as {@see Readiness}:
            // take the MINIMUM VDOT across categories, so every prescribed
            // pace stays within what at least one genuine PR has proven
            // reachable, never faster than the athlete's slowest relative PR.
            if ($bestVdot === null || $vdot < $bestVdot) {
                $bestVdot = $vdot;
                $best = $pr;
            }
        }

        if ($bestVdot === null || $best === null) {
            return null;
        }

        return [
            'vdot' => round($bestVdot, 1),
            'source_category' => $best->category->value,
            'set_at' => $best->set_at,
        ];
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
