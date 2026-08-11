<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Collection;

/**
 * Pure presentation logic for the /records hero scoreboard: which PR to feature,
 * its "Sub-{round}" milestone target, and the per-km splits pace strip.
 */
class PrScoreboardBuilder
{
    /**
     * Pick the PR to feature in the hero scoreboard: highest distance with a PR,
     * falling back to the first record.
     *
     * @param  Collection<int, PersonalRecord>  $records
     */
    public function pickFeaturedPr(Collection $records): ?PersonalRecord
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
     * @return array{pr_id:int,splits_pace_sec:array<int,int>,splits_partial_pace_sec:int|null,location_name:string|null,weather_temp_c:int|null,weather_humidity_pct:int|null,target_sec:int|null,delta_sec:int|null}|null
     */
    public function featuredExtras(?PersonalRecord $pr): ?array
    {
        if ($pr === null) {
            return null;
        }

        /** @var ActivityDetail|null $detail */
        $detail = $pr->activity?->detail;
        $summary = StreamSummary::fromArray($detail?->streamSummary());

        return [
            'pr_id' => $pr->id,
            'splits_pace_sec' => $this->splitsPaceSec($summary->perKm()),
            'splits_partial_pace_sec' => $this->splitsPartialPaceSec($summary->partialSplit()),
            'location_name' => $detail?->location_name,
            'weather_temp_c' => $detail?->weather_temp_c,
            'weather_humidity_pct' => $detail?->weather_humidity_pct,
            ...$this->milestoneFor($pr),
        ];
    }

    /**
     * Flatten `stream_summary.per_km` into a list of pace-seconds-per-km. Every
     * row there is already a full kilometre on the watch's own elapsed basis;
     * the trailing sub-km "sisa" row lives in `partial_split` (see
     * splitsPartialPaceSec) so the sparkline's verdict, crown, and bucketing
     * only ever weigh full kms.
     *
     * @param  array<int, array<string, mixed>>|null  $perKm
     * @return array<int, int>
     */
    public function splitsPaceSec(?array $perKm): array
    {
        $out = [];
        foreach ($perKm ?? [] as $row) {
            $paceSec = $this->paceSec($row);
            if ($paceSec !== null) {
                $out[] = $paceSec;
            }
        }

        return $out;
    }

    /**
     * Per-km-normalized pace of the trailing "sisa" row, or null when the run
     * ends on a full km. Rendered as a de-emphasized, non-crownable ghost bar;
     * kept out of splitsPaceSec so it never skews the scale.
     *
     * @param  array<string, mixed>|null  $partial
     */
    public function splitsPartialPaceSec(?array $partial): ?int
    {
        return $partial === null ? null : $this->paceSec($partial);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function paceSec(array $row): ?int
    {
        $pace = $row['pace'] ?? null;
        $paceSec = is_string($pace) ? PaceFormatter::parse($pace) : null;

        return $paceSec === null || $paceSec <= 0 ? null : (int) round($paceSec);
    }

    /**
     * "Sub-{next round minute} di {category}" heuristic. For 1:02:27 →
     * target Sub-1:00:00, delta 2:27.
     *
     * @return array{target_sec:int|null, delta_sec:int|null}
     */
    public function milestoneFor(PersonalRecord $pr): array
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

    public function roundedTargetSec(int $current): int
    {
        if ($current >= 3600) {
            // Hour-scale: drop to next-lower 5-min increment (e.g., 1:02 → 1:00).
            // >= (not >) so a PR of exactly 1:00:00 stays in the same 5-min
            // bucket as its neighbors instead of falling into the 1-min bucket.
            return (intdiv($current - 1, 300)) * 300;
        }
        if ($current > 600) {
            // 10+ min: round down to next minute.
            return (intdiv($current - 1, 60)) * 60;
        }

        // Sub-10-min: round down to next 15s.
        return (intdiv($current - 1, 15)) * 15;
    }
}
