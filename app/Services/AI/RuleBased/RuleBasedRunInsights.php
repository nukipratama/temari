<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\PaceConsistency;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Deterministic run-insight copy for the demo seed and the unconfigured-Azure
 * path, read straight off the run's own stored summary.
 *
 * This is a stand-in, not a second implementation of the narrators. It exists
 * so `demo:seed` can fill its three run-insight blocks with the run's real
 * cadence, splits and zones instead of lorem, without spending LLM tokens. It
 * deliberately stops at what a single ActivityDetail can answer: no rolling
 * pace average over the user's history, no VDOT-derived easy-pace nudge. Real
 * narration is {@see \App\Services\AI\Narrators\RunInsightNarrator}, which
 * reaches for that history through its tools.
 */
final readonly class RuleBasedRunInsights
{
    // Cadence thresholds (spm, already doubled)
    private const int CADENCE_IDEAL = 180;
    private const int CADENCE_MODERATE = 170;
    private const int CADENCE_LOW = 160;

    // Average HR as a percentage of the run's own peak
    private const int HR_RESERVE_EASY = 70;
    private const int HR_RESERVE_MODERATE = 80;
    private const int HR_RESERVE_HARD = 90;

    // Decoupling (% pace drift)
    private const int DECOUPLING_HIGH = 5;
    private const int DECOUPLING_OK = 2;

    /** Above this temperature high decoupling is the weather, not lost fitness. */
    private const int DECOUPLING_HOT_TEMP_C = 31;

    // Spread between the fastest and slowest km (sec/km)
    private const int PACE_DIFF_NOTICEABLE = 15;
    private const int PACE_DIFF_WIDE = 30;

    /** Second half slower than the first by this fraction reads as a positive split. */
    private const float POSITIVE_SPLIT_MARGIN = 0.015;

    /** Opener frames, so identical-metric runs don't all read "Sesi ini ...". */
    private const array TECHNICAL_FRAMES = [
        'Sesi ini %s.',
        'Catatan teknisnya, %s.',
        'Dari angka-angkanya, %s.',
        'Baca teknisnya: %s.',
    ];

    public function technical(ActivityDetail $detail): string
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());

        $parts = [];
        $this->appendCadencePart($detail, $parts);
        $this->appendHrPart($detail, $parts);
        $this->appendDecouplingPart($detail, $summary, $parts);
        $this->appendElevationPart($detail, $parts);
        if (PaceConsistency::isNotablyUneven($summary->paceVariabilitySec())) {
            $parts[] = 'pace agak bervariasi, coba jaga konsistensi';
        }

        if ($parts === []) {
            return 'Sesi ini metrik-nya konsisten, gak ada yang mencolok.';
        }

        $frame = self::TECHNICAL_FRAMES[$detail->activity_id % count(self::TECHNICAL_FRAMES)];

        return sprintf($frame, implode(', ', $parts));
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendCadencePart(ActivityDetail $detail, array &$parts): void
    {
        if ($detail->average_cadence === null) {
            return;
        }

        $cadence = (int) round($detail->average_cadence * 2);
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
        if ($detail->average_heartrate === null) {
            return;
        }

        $avgHr = (int) round($detail->average_heartrate);
        $maxHr = $detail->max_heartrate;
        if ($maxHr === null || $maxHr <= 0) {
            $parts[] = "HR rata-rata {$avgHr}";

            return;
        }

        $share = round(($avgHr / $maxHr) * 100);
        $label = match (true) {
            $share <= self::HR_RESERVE_EASY => 'zona nyaman',
            $share <= self::HR_RESERVE_MODERATE => 'zona sedang',
            $share <= self::HR_RESERVE_HARD => 'intens tinggi',
            default => 'sangat intens',
        };
        $parts[] = "HR rata-rata {$avgHr} ({$label})";
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendDecouplingPart(ActivityDetail $detail, StreamSummary $summary, array &$parts): void
    {
        $decoupling = $summary->decouplingPct();
        if ($decoupling === null) {
            return;
        }

        $label = 'decoupling +' . DecimalFormatter::decimal($decoupling) . '%';

        if ($decoupling > self::DECOUPLING_HIGH) {
            $temp = $detail->weather_temp_c;
            $parts[] = $temp !== null && $temp >= self::DECOUPLING_HOT_TEMP_C
                ? "{$label}, tapi wajar soalnya tadi panas ~{$temp}°C"
                : "{$label}, aerobik base belum solid";
        } elseif ($decoupling > self::DECOUPLING_OK) {
            $parts[] = "{$label}, masih wajar";
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendElevationPart(ActivityDetail $detail, array &$parts): void
    {
        $ascent = $detail->total_elevation_gain;
        if ($ascent !== null && (float) $ascent > 50) {
            $parts[] = 'elevation gain ' . ((int) $ascent) . 'm';
        }
    }

    public function splits(ActivityDetail $detail): string
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        /** @var array<int, array{km: int, pace: string}> $perKm */
        $perKm = $summary->perKm() ?? [];

        /** @var list<string> $parts */
        $parts = [];
        if (count($perKm) >= 2) {
            $consistencyStated = $this->appendSplitDirectionPart($summary, $perKm, $parts);
            $this->appendKmRangePart($perKm, $parts);
            $this->appendVariabilityCommentPart($summary, $parts, $consistencyStated);
        }
        // The trailing "sisa" segment stands on its own, so it survives runs too
        // short for a full-km split analysis (1.x km, or a sub-km run).
        $this->appendFinishPart($summary, $parts);

        if ($parts === []) {
            return 'Data split belum cukup buat dianalisis.';
        }

        return implode(' ', array_map(fn (string $part): string => ucfirst($part) . '.', $parts));
    }

    /**
     * Describes split direction. A genuine negative split is flagged upstream on
     * the summary; otherwise the run is either a positive split or an even
     * effort, told apart here from the per-km paces.
     *
     * Returns true when the note already asserted pace consistency, so the
     * variability layer can skip restating the same idea.
     *
     * @param  array<int, array{km: int, pace: string}>  $perKm
     * @param  list<string>  $parts
     */
    private function appendSplitDirectionPart(StreamSummary $summary, array $perKm, array &$parts): bool
    {
        if ($summary->negativeSplit() === true) {
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
     * @param  array<int, array{km: int, pace: string}>  $perKm
     */
    private function isPositiveSplit(array $perKm): bool
    {
        $paces = array_values(array_filter(
            array_map(fn (array $km): ?int => $this->parsePaceToSeconds($km['pace']), $perKm),
            fn (?int $pace): bool => $pace !== null,
        ));

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
     * @param  array<int, array{km: int, pace: string}>  $perKm
     * @param  list<string>  $parts
     */
    private function appendKmRangePart(array $perKm, array &$parts): void
    {
        $paces = [];
        foreach ($perKm as $km) {
            $parsed = $this->parsePaceToSeconds($km['pace']);
            if ($parsed !== null) {
                $paces[$km['km']] = $parsed;
            }
        }

        if (count($paces) < 3) {
            return;
        }

        $fastest = (int) array_keys($paces, min($paces), true)[0];
        $slowest = (int) array_keys($paces, max($paces), true)[0];
        $rangeSec = max($paces) - min($paces);

        $parts[] = match (true) {
            $rangeSec > self::PACE_DIFF_WIDE => $this->kmRangeWide($perKm, $fastest, $slowest),
            $rangeSec > self::PACE_DIFF_NOTICEABLE => "km {$fastest} tercepat, gap-nya wajar",
            default => 'gap antar km sangat kecil',
        };
    }

    /**
     * @param  array<int, array{km: int, pace: string}>  $perKm
     */
    private function kmRangeWide(array $perKm, int $fastest, int $slowest): string
    {
        $idx = array_search($fastest, array_column($perKm, 'km'), true);
        $fastestPace = $perKm[$idx !== false ? $idx : 0]['pace'] ?? '?';

        return "km {$fastest} tercepat ({$fastestPace}), km {$slowest} paling lambat, selisih cukup besar";
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendVariabilityCommentPart(StreamSummary $summary, array &$parts, bool $consistencyStated): void
    {
        if ($consistencyStated) {
            return;
        }

        $raw = $summary->paceVariabilitySec();
        if (! PaceConsistency::isPraiseworthy($raw)) {
            return;
        }

        $parts[] = PaceConsistency::isVeryEven($raw)
            ? 'konsistensi pace sangat bagus'
            : 'konsistensi pace cukup baik';
    }

    /**
     * Note the trailing "sisa" segment (e.g. the last 0.7 km of a 5.7 km run) as
     * a finish, without treating it as a full km. Skipped when the run ends on a
     * whole km.
     *
     * @param  list<string>  $parts
     */
    private function appendFinishPart(StreamSummary $summary, array &$parts): void
    {
        $partial = $summary->partialSplit();
        if ($partial === null || ! isset($partial['distance_m'], $partial['pace'])) {
            return;
        }

        $km = DistanceFormatter::kmString((float) $partial['distance_m']);
        $parts[] = "sisa {$km} km ditutup di {$partial['pace']}";
    }

    public function zones(ActivityDetail $detail): string
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $zonePct = $this->resolveZonePercentages($summary);

        if ($zonePct === []) {
            return 'Data heart rate zone belum tersedia.';
        }

        /** @var list<string> $parts */
        $parts = [];

        $dominantZone = array_keys($zonePct, max($zonePct), true);
        $dominantPct = (float) ($zonePct[$dominantZone[0] ?? 'Z2'] ?? 0);
        if ($dominantPct > 0) {
            $dominantLabel = DecimalFormatter::trimmed($dominantPct);
            $parts[] = $dominantPct >= 70
                ? "{$dominantLabel}% di {$dominantZone[0]}"
                : "didominasi {$dominantZone[0]} ({$dominantLabel}%)";
        }

        $easyPct = (float) ($zonePct['Z1'] ?? 0) + (float) ($zonePct['Z2'] ?? 0);
        $hardPct = $summary->hardZoneShare();
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

        if (((float) ($zonePct['Z5'] ?? 0)) > 10) {
            $parts[] = 'Z5 cukup banyak, pastikan recovery cukup';
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * @return array<string, float>
     */
    private function resolveZonePercentages(StreamSummary $summary): array
    {
        $zonePct = $summary->zonePct();
        if ($zonePct !== []) {
            return $zonePct;
        }

        $zoneMin = $summary->zoneMinutes();
        if ($zoneMin === null) {
            return [];
        }

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
     * Parse a pace string like "5:32" into total seconds.
     */
    private function parsePaceToSeconds(string $pace): ?int
    {
        $parts = explode(':', $pace);
        if (count($parts) !== 2) {
            return null;
        }

        return ((int) $parts[0]) * 60 + (int) $parts[1];
    }
}
