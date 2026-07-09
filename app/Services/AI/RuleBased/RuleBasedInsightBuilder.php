<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
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
final readonly class RuleBasedInsightBuilder
{
    public function __construct(
        private VdotEstimator $vdotEstimator = new VdotEstimator(),
        private TrainingPaceCalculator $trainingPaceCalculator = new TrainingPaceCalculator(),
    ) {
    }

    // Cadence thresholds (spm, already doubled)
    private const int CADENCE_IDEAL = 180;
    private const int CADENCE_MODERATE = 170;
    private const int CADENCE_LOW = 160;

    // HR reserve (% of max)
    private const int HR_RESERVE_EASY = 70;
    private const int HR_RESERVE_MODERATE = 80;
    private const int HR_RESERVE_HARD = 90;

    // Decoupling (% pace drift)
    private const int DECOUPLING_HIGH = 5;
    private const int DECOUPLING_OK = 2;

    // Weather temp (C) above which high decoupling is expected, not alarming
    // (matches the "panas" threshold used elsewhere, e.g. Temari.php, RunCardFactory.php)
    private const int DECOUPLING_HOT_TEMP_C = 31;

    // Grey-zone nudge: an easy-paced/easy-HR run that drifted into excessive
    // Z3+ effort. Thresholds are intentionally strict (anti-nag guard) so an
    // ordinary firm run never trips this.
    private const int GREY_ZONE_HARD_SHARE_MIN = 55; // Z3+Z4+Z5 pct
    private const int GREY_ZONE_MIN_DISTANCE_M = 3000;
    private const int GREY_ZONE_MAX_DISTANCE_M = 15000;
    private const int GREY_ZONE_PACE_MARGIN_SEC = 60; // current pace must be this much slower than threshold pace

    // Pace variability (seconds)
    private const int VARIABILITY_CONSISTENT = 8;
    private const int VARIABILITY_MODERATE = 15;
    private const int VARIABILITY_HIGH = 20;

    // Pace diff vs user average (sec/km)
    private const int PACE_DIFF_NOTICEABLE = 15;
    private const int PACE_DIFF_WIDE = 30;

    // Recent-window size for the rolling pace average (runs)
    private const int ROLLING_PACE_WINDOW = 30;

    // Positive-split detection: second half slower than first by this fraction
    private const float POSITIVE_SPLIT_MARGIN = 0.015;

    // Opener frames for the technical note, so identical-metric runs don't all
    // read "Sesi ini ...". Picked deterministically by activity id (idempotent).
    private const array TECHNICAL_FRAMES = [
        'Sesi ini %s.',
        'Catatan teknisnya, %s.',
        'Dari angka-angkanya, %s.',
        'Baca teknisnya: %s.',
    ];

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
        $this->appendDecouplingPart($detail, $summary, $parts);
        $this->appendElevationPart($summary, $parts);
        $this->appendPaceVariabilityPart($summary, $parts);
        $this->appendPaceComparisonPart($activity, $detail, $parts);

        if ($parts === []) {
            return 'Sesi ini metrik-nya konsisten, gak ada yang mencolok.';
        }

        $frame = self::TECHNICAL_FRAMES[$activity->id % count(self::TECHNICAL_FRAMES)];

        return sprintf($frame, implode(', ', $parts));
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
            $cadence >= self::CADENCE_IDEAL => 'ideal',
            $cadence >= self::CADENCE_MODERATE => 'lumayan',
            $cadence >= self::CADENCE_LOW => 'masih bisa dinaikin',
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
            $hrReserve <= self::HR_RESERVE_EASY => 'zona nyaman',
            $hrReserve <= self::HR_RESERVE_MODERATE => 'zona sedang',
            $hrReserve <= self::HR_RESERVE_HARD => 'intens tinggi',
            default => 'sangat intens',
        };
        $parts[] = "HR rata-rata {$avgHr} ({$label})";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendDecouplingPart(ActivityDetail $detail, array $summary, array &$parts): void
    {
        $raw = $summary['decoupling_pct'] ?? null;
        if ($raw === null) {
            return;
        }

        $decoupling = (float) $raw;
        if ($decoupling > self::DECOUPLING_HIGH) {
            $parts[] = $this->decouplingHighPart($decoupling, $detail->weather_temp_c);
        } elseif ($decoupling > self::DECOUPLING_OK) {
            $parts[] = 'decoupling +' . number_format($decoupling, 1) . '%, masih wajar';
        }
    }

    /**
     * High decoupling reads as lost aerobic efficiency, but heat alone can
     * drive the same cardiac drift in an otherwise solid aerobic base. Soften
     * the message instead of implying lost fitness when the weather explains it.
     */
    private function decouplingHighPart(float $decoupling, ?int $weatherTempC): string
    {
        $label = 'decoupling +' . number_format($decoupling, 1) . '%';

        if ($weatherTempC !== null && $weatherTempC >= self::DECOUPLING_HOT_TEMP_C) {
            return "{$label}, tapi wajar soalnya tadi panas ~{$weatherTempC}°C";
        }

        return "{$label}, aerobik base belum solid";
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
        if ($raw !== null && (float) $raw > self::VARIABILITY_HIGH) {
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
        if ($diff > self::PACE_DIFF_NOTICEABLE) {
            $parts[] = 'lebih cepat dari rata-rata kamu';
        } elseif ($diff < -self::PACE_DIFF_NOTICEABLE) {
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
        $consistencyStated = $this->appendSplitDirectionPart($summary, $perKm, $parts);
        $this->appendKmRangePart($perKm, $parts);
        $this->appendVariabilityCommentPart($summary, $parts, $consistencyStated);

        return $this->joinSentences($parts);
    }

    /**
     * Join clauses into sentences, capitalising the first letter of each so a
     * multi-clause note reads sentence-cased (not "Foo. bar. baz.").
     *
     * @param  list<string>  $parts
     */
    private function joinSentences(array $parts): string
    {
        return implode(' ', array_map(fn (string $part): string => ucfirst($part) . '.', $parts));
    }

    /**
     * Describes split direction. A genuine negative split (second half faster)
     * is flagged upstream via `negative_split`. When that is not set, the run is
     * either a genuine positive split (second half meaningfully slower) or an
     * even effort, distinguished here from the per-km paces.
     *
     * Returns true when the note already asserted pace consistency (an even
     * effort), so the variability layer can skip restating the same idea.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<int, array{km: int, pace: string}>  $perKm
     * @param  list<string>  $parts
     */
    private function appendSplitDirectionPart(array $summary, array $perKm, array &$parts): bool
    {
        if (($summary['negative_split'] ?? null) === true) {
            $parts[] = 'negative split, paruh kedua lebih cepat dari awal';

            return false;
        }

        if ($this->isPositiveSplit($perKm)) {
            $parts[] = 'positive split, pace melambat di paruh kedua';

            return false;
        }

        $parts[] = 'pacing cukup merata dari awal sampai akhir';

        return true;
    }

    /**
     * True when the second half is meaningfully slower than the first, i.e. the
     * mean second-half pace (sec/km) exceeds the first-half mean by the margin.
     *
     * @param  array<int, array{km: int, pace: string}>  $perKm
     */
    private function isPositiveSplit(array $perKm): bool
    {
        $paces = [];
        foreach ($perKm as $km) {
            $parsed = $this->parsePaceToSeconds($km['pace']);
            if ($parsed !== null) {
                $paces[] = $parsed;
            }
        }

        if (count($paces) < 2) {
            return false;
        }

        $half = (int) ceil(count($paces) / 2);
        $firstHalf = array_slice($paces, 0, $half);
        $secondHalf = array_slice($paces, $half);
        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);

        return $secondAvg > $firstAvg * (1 + self::POSITIVE_SPLIT_MARGIN);
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
            $rangeSec > self::PACE_DIFF_WIDE => $this->kmRangeWide($perKm, $fastest, $slowest),
            $rangeSec > self::PACE_DIFF_NOTICEABLE => "km {$fastest} tercepat, gap-nya wajar",
            default => 'gap antar km sangat kecil',
        };
    }

    /**
     * @param array<int, array{km: int, pace: string}> $perKm
     */
    private function kmRangeWide(array $perKm, int $fastest, int $slowest): string
    {
        $idx = array_search($fastest, array_column($perKm, 'km'), true);
        $fastestPace = $perKm[$idx !== false ? $idx : 0]['pace'] ?? '?';

        return "km {$fastest} tercepat ({$fastestPace}), km {$slowest} paling lambat, selisih cukup besar";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $parts
     */
    private function appendVariabilityCommentPart(array $summary, array &$parts, bool $consistencyStated): void
    {
        if ($consistencyStated) {
            return;
        }

        $raw = $summary['pace_variability_sec'] ?? null;
        if ($raw === null) {
            return;
        }

        $variability = (float) $raw;
        if ($variability <= self::VARIABILITY_CONSISTENT) {
            $parts[] = 'konsistensi pace sangat bagus';
        } elseif ($variability <= self::VARIABILITY_MODERATE) {
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
        $this->appendGreyZoneNudge($detail, $zonePct, $parts);

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
     * Grey-zone nudge: a run that read as intended-easy (pace clearly slower
     * than threshold, or HR reads comfortable when threshold is unknown) but
     * still drifted into excessive Z3+ effort, defeating the aerobic-base
     * purpose of an easy run. Thresholds are strict by design (anti-nag guard)
     * so an ordinary firm run never trips this.
     *
     * @param  array<string, float>  $zonePct
     * @param  list<string>  $parts
     */
    private function appendGreyZoneNudge(ActivityDetail $detail, array $zonePct, array &$parts): void
    {
        $hardShare = (float) ($zonePct['Z3'] ?? 0) + (float) ($zonePct['Z4'] ?? 0) + (float) ($zonePct['Z5'] ?? 0);
        if ($hardShare < self::GREY_ZONE_HARD_SHARE_MIN) {
            return;
        }

        $distance = $detail->distance;
        if ($distance === null || $distance < self::GREY_ZONE_MIN_DISTANCE_M || $distance > self::GREY_ZONE_MAX_DISTANCE_M) {
            return;
        }

        $currentPace = $detail->paceSecPerKm();
        if ($currentPace === null) {
            return;
        }

        $easyPaceLabel = $this->greyZoneEasyPaceLabel($detail, $currentPace);
        if ($easyPaceLabel === false) {
            return;
        }

        $parts[] = $easyPaceLabel !== null
            ? "kalau niatnya easy, coba tahan di sekitar {$easyPaceLabel}/km biar aerobiknya lebih kebentuk"
            : 'kalau niatnya easy, coba lebih pelan dikit biar aerobiknya lebih kebentuk';
    }

    /**
     * Confirms the run reads as intended-easy and resolves the pace label to
     * quote back, when a VDOT-derived threshold pace is available.
     *
     * Returns `false` when the run does not read as easy (caller should skip
     * the nudge), a formatted "m:ss" pace label when VDOT is available, or
     * `null` when the HR-only fallback confirmed "easy" without a pace number.
     */
    private function greyZoneEasyPaceLabel(ActivityDetail $detail, float $currentPace): string|false|null
    {
        // The zones insight is relation-optional (it can run on an unpersisted
        // detail), so degrade to the HR-only path when no activity/user resolves.
        /** @var Activity|null $activity */
        $activity = $detail->activity;
        $vdotResult = $activity !== null
            ? $this->vdotEstimator->estimate($activity->user)
            : null;

        if ($vdotResult !== null) {
            $paces = $this->trainingPaceCalculator->fromVdot($vdotResult['vdot']);
            if ($currentPace - $paces['threshold'] < self::GREY_ZONE_PACE_MARGIN_SEC) {
                return false;
            }

            return PaceFormatter::format((float) $paces['easy']);
        }

        $avgHr = $detail->average_heartrate;
        $maxHr = $detail->max_heartrate;
        if ($avgHr === null || $maxHr === null || $maxHr <= 0) {
            return false;
        }

        return (($avgHr / $maxHr) * 100) <= self::HR_RESERVE_EASY ? null : false;
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
     * User's rolling average pace (sec/km) over their most recent runs.
     *
     * The pace of each of the last {@see self::ROLLING_PACE_WINDOW} runs is
     * fetched, then averaged in PHP. Averaging directly in SQL alongside a
     * LIMIT would not work: AVG() collapses to a single row, so the LIMIT is
     * ignored and the all-time average leaks through instead of the recent one.
     */
    private function userAveragePace(int $userId): ?float
    {
        $paces = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.distance')
            ->where('activity_details.distance', '>', 0)
            ->whereNotNull('activity_details.moving_time')
            ->where('activity_details.moving_time', '>', 0)
            ->orderByDesc('activity_details.start_date_local')
            ->limit(self::ROLLING_PACE_WINDOW)
            ->select('activity_details.distance', 'activity_details.moving_time')
            ->get()
            ->map(fn (ActivityDetail $detail): ?float => $detail->paceSecPerKm())
            ->filter(fn (?float $pace): bool => $pace !== null);

        if ($paces->isEmpty()) {
            return null;
        }

        return $paces->sum() / $paces->count();
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
