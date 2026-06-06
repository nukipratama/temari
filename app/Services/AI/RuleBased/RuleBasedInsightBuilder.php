<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

/**
 * Deterministic, rule-based replacements for the LLM-powered insight narrators.
 * Generates the same plain-string output format that the frontend expects, but
 * using only arithmetic comparisons against activity data and user history.
 *
 * Converted types (no LLM tokens):
 * - RunInsightTechnical
 * - RunInsightSplits
 * - RunInsightZones
 * - TrendCaption
 */
final class RuleBasedInsightBuilder
{
    /**
     * @return array{technical: string, splits: string, zones: string}
     */
    public function runInsights(Activity $activity, ActivityDetail $detail): array
    {
        return [
            'technical' => $this->runInsightTechnical($activity, $detail),
            'splits' => $this->runInsightSplits($detail),
            'zones' => $this->runInsightZones($detail),
        ];
    }

    /**
     * Technical insight: cadence, HR, decoupling, elevation, compared to
     * the user's own rolling averages.
     */
    public function runInsightTechnical(Activity $activity, ActivityDetail $detail): string
    {
        $summary = $detail->streamSummary();
        $parts = [];
        $this->appendCadencePart($detail, $parts);
        $this->appendHrPart($detail, $parts);
        $this->appendDecouplingPart($summary, $parts);
        $this->appendElevationPart($summary, $parts);
        $this->appendPaceVariabilityPart($summary, $parts);
        $this->appendPaceComparisonPart($activity, $detail, $parts);

        if ($parts === []) {
            return 'Sesi ini metrik-nya konsisten, gak ada yang mencolok.';
        }

        return 'Sesi ini ' . implode(', ', $parts) . '.';
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendCadencePart(ActivityDetail $detail, array &$parts): void
    {
        $cadence = $detail->average_cadence !== null
            ? (int) round($detail->average_cadence * 2)
            : null;
        if ($cadence === null) {
            return;
        }

        $label = match (true) {
            $cadence >= 180 => 'ideal',
            $cadence >= 170 => 'lumayan',
            $cadence >= 160 => 'masih bisa dinaikin',
            default => 'cukup rendah',
        };
        $parts[] = "cadence {$cadence} spm ({$label})";
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendHrPart(ActivityDetail $detail, array &$parts): void
    {
        $avgHr = $detail->average_heartrate !== null
            ? (int) round($detail->average_heartrate)
            : null;
        if ($avgHr === null) {
            return;
        }

        $maxHr = $detail->max_heartrate;
        if ($maxHr === null || $maxHr <= 0) {
            $parts[] = "HR rata-rata {$avgHr}";

            return;
        }

        $hrReserve = round(($avgHr / $maxHr) * 100);
        $label = match (true) {
            $hrReserve <= 70 => 'zona nyaman',
            $hrReserve <= 80 => 'zona sedang',
            $hrReserve <= 90 => 'intens tinggi',
            default => 'sangat intens',
        };
        $parts[] = "HR rata-rata {$avgHr} ({$label})";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendDecouplingPart(array $summary, array &$parts): void
    {
        $raw = $summary['decoupling_pct'] ?? null;
        if ($raw === null) {
            return;
        }

        $decoupling = (float) $raw;
        if ($decoupling > 5) {
            $parts[] = 'decoupling +' . number_format($decoupling, 1) . '%, aerobik base belum solid';
        } elseif ($decoupling > 2) {
            $parts[] = 'decoupling +' . number_format($decoupling, 1) . '%, masih wajar';
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendElevationPart(array $summary, array &$parts): void
    {
        $ascent = $summary['ascent_m'] ?? null;
        if ($ascent !== null && (float) $ascent > 50) {
            $parts[] = 'elevation gain ' . ((int) $ascent) . 'm';
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendPaceVariabilityPart(array $summary, array &$parts): void
    {
        $raw = $summary['pace_variability_sec'] ?? null;
        if ($raw !== null && (float) $raw > 20) {
            $parts[] = 'pace agak bervariasi, coba jaga konsistensi';
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendPaceComparisonPart(Activity $activity, ActivityDetail $detail, array &$parts): void
    {
        $userAvg = $this->userAveragePace($activity->user_id);
        $currentPace = $detail->paceSecPerKm();
        if ($userAvg === null || $currentPace === null) {
            return;
        }

        $diff = $userAvg - $currentPace; // positive = faster than average
        if ($diff > 15) {
            $parts[] = 'lebih cepat dari rata-rata kamu';
        } elseif ($diff < -15) {
            $parts[] = 'lebih santai dari biasanya';
        }
    }

    /**
     * Splits insight: identify positive/negative split, fastest/slowest km,
     * pacing consistency.
     */
    public function runInsightSplits(ActivityDetail $detail): string
    {
        $summary = $detail->streamSummary();
        /** @var array<int, array{km: int, pace: string}>|null $perKm */
        $perKm = $summary['per_km'] ?? null;
        if (! is_array($perKm) || count($perKm) < 2) {
            return 'Data split belum cukup buat dianalisis.';
        }

        /** @var list<string> $parts */
        $parts = [];
        $this->appendSplitDirectionPart($summary, $parts);
        $this->appendKmRangePart($perKm, $parts);
        $this->appendVariabilityCommentPart($summary, $parts);

        return ucfirst(implode('. ', $parts)) . '.';
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendSplitDirectionPart(array $summary, array &$parts): void
    {
        $parts[] = match ($summary['negative_split'] ?? null) {
            true => 'negative split, paruh kedua lebih cepat dari awal',
            false => 'positive split, pace melambat di paruh kedua',
            default => 'pacing cukup merata dari awal sampai akhir',
        };
    }

    /**
     * @param array<int, array{km: int, pace: string}> $perKm
     * @param list<string> $parts
     */
    private function appendKmRangePart(array $perKm, array &$parts): void
    {
        $paces = [];
        foreach ($perKm as $km) {
            $parsed = $this->parsePaceToSeconds($km['pace'] ?? '');
            if ($parsed !== null) {
                $paces[$km['km']] = $parsed;
            }
        }

        if (count($paces) < 3) {
            return;
        }

        $fastest = (int) array_keys($paces, min($paces), true)[0];
        $slowest = (int) array_keys($paces, max($paces), true)[0];
        $rangeSec = max(array_values($paces)) - min(array_values($paces));

        $parts[] = match (true) {
            $rangeSec > 30 => $this->kmRangeWide($perKm, $fastest, $slowest),
            $rangeSec > 15 => "km {$fastest} tercepat, gap-nya wajar",
            default => 'gap antar km sangat kecil, pacing sangat konsisten',
        };
    }

    /**
     * @param array<int, array{km: int, pace: string}> $perKm
     */
    private function kmRangeWide(array $perKm, int $fastest, int $slowest): string
    {
        $idx = array_search($fastest, array_column($perKm, 'km'), true);
        $fastestPace = $perKm[$idx ?: 0]['pace'] ?? '?';

        return "km {$fastest} tercepat ({$fastestPace}), km {$slowest} paling lambat, selisih cukup besar";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendVariabilityCommentPart(array $summary, array &$parts): void
    {
        $raw = $summary['pace_variability_sec'] ?? null;
        if ($raw === null) {
            return;
        }

        $variability = (float) $raw;
        if ($variability <= 8) {
            $parts[] = 'konsistensi pace sangat bagus';
        } elseif ($variability <= 15) {
            $parts[] = 'konsistensi pace cukup baik';
        }
    }

    /**
     * HR zones insight: time-in-zone breakdown, zone discipline assessment.
     */
    public function runInsightZones(ActivityDetail $detail): string
    {
        $summary = $detail->streamSummary();
        $zonePct = $this->resolveZonePercentages($summary);

        if ($zonePct === []) {
            return 'Data heart rate zone belum tersedia.';
        }

        /** @var list<string> $parts */
        $parts = [];
        $this->appendZoneAnalysis($zonePct, $summary, $parts);

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, float>
     */
    private function resolveZonePercentages(array $summary): array
    {
        $zonePct = StreamSummary::zonePct($summary);
        if ($zonePct !== []) {
            return $zonePct;
        }

        $zoneMin = $summary['time_in_zone_min'] ?? null;
        if (! is_array($zoneMin)) {
            return [];
        }

        return $this->deriveZonePctFromMinutes($zoneMin);
    }

    /**
     * @param  array<string, mixed>  $zoneMin
     * @return array<string, float>
     */
    private function deriveZonePctFromMinutes(array $zoneMin): array
    {
        $totalMin = (float) array_sum($zoneMin);
        if ($totalMin <= 0) {
            return [];
        }

        return array_map(
            fn (mixed $min): float => round(((float) $min / $totalMin) * 100, 1),
            $zoneMin,
        );
    }

    /**
     * Appends dominant zone label and zone discipline assessment.
     *
     * @param array<string, float> $zonePct
     * @param array<string, mixed> $summary
     * @param list<string> $parts
     */
    private function appendZoneAnalysis(array $zonePct, array $summary, array &$parts): void
    {
        // Dominant zone
        $dominantZone = array_keys($zonePct, max($zonePct), true);
        $dominantPct = (float) ($zonePct[$dominantZone[0] ?? 'Z2'] ?? 0);
        if ($dominantPct > 0) {
            $parts[] = $dominantPct >= 70
                ? "{$dominantPct}% di {$dominantZone[0]}"
                : "didominasi {$dominantZone[0]} ({$dominantPct}%)";
        }

        // Discipline assessment
        $easyPct = (float) ($zonePct['Z1'] ?? 0) + (float) ($zonePct['Z2'] ?? 0);
        $hardPct = StreamSummary::hardZoneShare($summary);
        $discipline = match (true) {
            $easyPct >= 80 => 'base building proper, mayoritas easy',
            $easyPct >= 60 => 'kombinasi easy dan moderate, seimbang',
            $hardPct >= 50 => 'intensitas tinggi, hati-hati overstrain',
            $hardPct >= 30 => 'ada porsi quality yang cukup',
            default => null,
        };
        if ($discipline !== null) {
            $parts[] = $discipline;
        }

        // Z5 warning
        if (((float) ($zonePct['Z5'] ?? 0)) > 10) {
            $parts[] = 'Z5 cukup banyak, pastikan recovery cukup';
        }
    }

    /**
     * Short trend caption comparing recent activity to prior period.
     */
    public function trendCaption(User $user, Carbon $asOf): string
    {
        $recentWeeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $asOf->toDateString())
            ->orderByDesc('week_ending')
            ->limit(8)
            ->get()
            ->reverse()
            ->values();

        if ($recentWeeks->count() < 2) {
            return 'Belum cukup data buat liat tren.';
        }

        // Split into recent (last 4) and prior (before that)
        $mid = (int) ceil($recentWeeks->count() / 2);
        $recent = $recentWeeks->slice($mid);
        $prior = $recentWeeks->slice(0, $mid);

        $recentDist = (float) $recent->avg('distance_km');
        $priorDist = (float) $prior->avg('distance_km');
        $recentForm = (float) $recent->avg('form');
        $priorForm = (float) $prior->avg('form');

        $parts = [];

        // Volume trend
        if ($priorDist > 0) {
            $volumeChange = (($recentDist - $priorDist) / $priorDist) * 100;
            $parts[] = match (true) {
                $volumeChange > 20 => 'volume naik signifikan',
                $volumeChange > 5 => 'volume naik pelan-pelan',
                $volumeChange > -5 => 'volume stabil',
                $volumeChange > -20 => 'volume turun dikit',
                default => 'volume turun cukup banyak',
            };
        }

        // Form trend
        $formDelta = $recentForm - $priorForm;
        $parts[] = match (true) {
            $formDelta > 5 => 'form lagi segar',
            $formDelta > 0 => 'form cukup baik',
            $formDelta > -5 => 'form di zona optimal',
            $formDelta > -10 => 'ada tanda fatigue',
            default => 'kelelahan terlampau tinggi, perlu rest',
        };

        // Latest form status
        $latestStatus = $recentWeeks->last()?->form_status;
        if ($latestStatus !== null) {
            $statusLabel = match ($latestStatus) {
                'fresh' => 'kondisi lagi fresh',
                'optimal' => 'di titik optimal',
                'fatigued' => 'mulai lelah',
                'overreaching' => 'waspada overreaching',
                default => null,
            };
            if ($statusLabel !== null) {
                $parts[] = $statusLabel;
            }
        }

        // CTL trend
        $recentCtl = (float) $recent->avg('ctl_42d');
        $priorCtl = (float) $prior->avg('ctl_42d');
        if ($priorCtl > 0 && $recentCtl > $priorCtl * 1.1) {
            $parts[] = 'fitness sedang membangun';
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * User's rolling average pace (sec/km) from their last 30 activities.
     */
    private function userAveragePace(int $userId): ?float
    {
        /** @var object{avg_pace: string|null}|null $row */
        $row = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.distance')
            ->where('activity_details.distance', '>', 0)
            ->whereNotNull('activity_details.moving_time')
            ->where('activity_details.moving_time', '>', 0)
            ->orderByDesc('activity_details.start_date_local')
            ->limit(30)
            ->selectRaw('AVG(activity_details.moving_time / (activity_details.distance / 1000)) as avg_pace')
            ->first();

        return $row?->avg_pace !== null ? (float) $row->avg_pace : null;
    }

    /**
     * Parse a pace string like "5:32" into total seconds.
     */
    private function parsePaceToSeconds(string $pace): ?int
    {
        if ($pace === '' || ! str_contains($pace, ':')) {
            return null;
        }

        $parts = explode(':', $pace);
        if (count($parts) !== 2) {
            return null;
        }

        $min = (int) $parts[0];
        $sec = (int) $parts[1];

        return $min * 60 + $sec;
    }
}
