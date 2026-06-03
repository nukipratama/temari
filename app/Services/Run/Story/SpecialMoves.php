<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

class SpecialMoves
{
    public const DEFAULT_MOVE = 'Easy Run'; // honest default — easy, no story

    /**
     * Pick a special-move name from the bucket the run falls into. Each bucket
     * holds a small pool of English names; the `seed` (a stable per-activity id)
     * deterministically selects one variant, so the same run always names the
     * same move while different runs in one bucket vary.
     *
     * @param  array<string, mixed>  $streamSummary
     * @param  array{distance_m?: float|null, pr_set?: bool, seed?: int}  $context
     */
    public function pick(array $streamSummary, array $context): string
    {
        $zonePct = is_array($streamSummary['time_in_zone_pct'] ?? null)
            ? $streamSummary['time_in_zone_pct']
            : [];
        $distribution = is_array($streamSummary['cadence_distribution_pct'] ?? null)
            ? $streamSummary['cadence_distribution_pct']
            : [];
        $negativeSplit = (bool) ($streamSummary['negative_split'] ?? false);
        $cadenceDropSpm = (float) ($streamSummary['cadence_drop_spm'] ?? 0.0);
        $distanceM = (float) ($context['distance_m'] ?? 0.0);
        $prSet = (bool) ($context['pr_set'] ?? false);
        $seed = (int) ($context['seed'] ?? 0);

        $z2 = (float) ($zonePct['Z2'] ?? 0.0);
        $z3 = (float) ($zonePct['Z3'] ?? 0.0);
        $z4 = (float) ($zonePct['Z4'] ?? 0.0);
        $z5 = (float) ($zonePct['Z5'] ?? 0.0);
        $hardShare = $z3 + $z4 + $z5;
        $fastCadenceShare = (float) ($distribution['>175'] ?? 0.0);

        // First matching bucket wins; order is the priority. Conditions are pure
        // booleans, so evaluating them all up-front is cheap and keeps the method
        // flat.
        $buckets = [
            [$prSet && $negativeSplit, ['Closing Kick', 'Late Surge', 'Final Gear']], // floored it late
            [$distanceM >= 10_000 && $hardShare < 5.0, ['Easy Miles', 'Cruise Control', 'Long & Chill']], // long but easy
            [$z3 > 60.0, ['Tempo Lock', 'Steady Tempo', 'Threshold Hold']], // held the tempo zone
            [$z4 + $z5 > 35.0, ['Red Line', 'All Out', 'Full Send']], // lived in the hard zones
            [$fastCadenceShare > 60.0, ['Machine Legs', 'Metronome', 'Quick Feet']], // locked, fast cadence
            [$z2 > 80.0, ['Easy Does It', 'Zone Two Zen', 'Calm & Steady']], // calm, patient zone-2
            [$prSet, ['New Record', 'Personal Best', 'Record Breaker']], // straight-up new PR
            [$cadenceDropSpm <= 1.0 && $distanceM >= 5_000, ['No Fade', 'Hold the Line', 'Never Dropped']], // cadence never faded
        ];

        foreach ($buckets as [$matched, $pool]) {
            if ($matched) {
                return $this->select($pool, $seed);
            }
        }

        return $this->select([self::DEFAULT_MOVE, 'Shakeout', 'Just Cruising'], $seed);
    }

    /**
     * @param  non-empty-list<string>  $pool
     */
    private function select(array $pool, int $seed): string
    {
        return $pool[abs($seed) % count($pool)];
    }
}
