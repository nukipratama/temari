<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\PaceConsistency;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Deterministic run-insight claims for the demo seed and the unconfigured-Azure
 * path, read straight off the run's own stored summary.
 *
 * This is a stand-in, not a second implementation of the narrator. It exists so
 * `demo:seed` can fill the run-insight block with the run's real numbers
 * instead of lorem, without spending LLM tokens. It deliberately stops at what
 * a single ActivityDetail can answer: no rolling pace average over the user's
 * history, no VDOT-derived easy-pace nudge. Real narration is
 * {@see \App\Services\AI\Narrators\RunInsightNarrator}, which reaches for that
 * history through its tools and picks claims with an LLM. Every claim this
 * class emits already anchors to real data, so it always survives the
 * narrator's own falsifiability check unchanged.
 */
final readonly class RuleBasedRunInsights
{
    /** Decoupling (% pace drift) */
    private const float DECOUPLING_HIGH = 5.0;

    private const float DECOUPLING_OK = 2.0;

    /** Above this temperature high decoupling is the weather, not lost fitness. */
    private const int DECOUPLING_HOT_TEMP_C = 31;

    /** A short punchy climb is worth a claim even without big total gain. */
    private const float NOTABLE_GRADE_PCT = 8.0;

    /** A zone holding this much of the session's time is worth naming. */
    private const float DOMINANT_ZONE_PCT = 60.0;

    private const int MAX_CLAIMS = 3;

    /**
     * Up to {@see self::MAX_CLAIMS} claims, most-notable first, built from
     * whichever real signals this run's stream summary actually carries.
     *
     * @return list<array{anchor: string, text: string, value: string|null, delta: string|null}>
     */
    public function claims(ActivityDetail $detail): array
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());

        $claims = [];
        $this->appendDecouplingClaim($detail, $summary, $claims);
        $this->appendSplitClaim($summary, $claims);
        $this->appendGradeClaim($summary, $claims);
        $this->appendZoneClaim($summary, $claims);
        $this->appendPaceVariabilityClaim($summary, $claims);

        return array_slice($claims, 0, self::MAX_CLAIMS);
    }

    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
     */
    private function appendDecouplingClaim(ActivityDetail $detail, StreamSummary $summary, array &$claims): void
    {
        $decoupling = $summary->decouplingPct();
        if ($decoupling === null) {
            return;
        }

        $value = '+'.DecimalFormatter::decimal($decoupling).'%';
        $text = match (true) {
            $decoupling > self::DECOUPLING_HIGH => $this->decouplingHighText($detail),
            $decoupling > self::DECOUPLING_OK => 'Decoupling stayed within a normal range, HR tracked pace pretty well.',
            default => 'Decoupling stayed tight, your aerobic fitness held up well across the run.',
        };

        $claims[] = ['anchor' => 'metric:decoupling', 'text' => $text, 'value' => $value, 'delta' => null];
    }

    private function decouplingHighText(ActivityDetail $detail): string
    {
        $temp = $detail->weather_temp_c;

        return $temp !== null && $temp >= self::DECOUPLING_HOT_TEMP_C
            ? "Decoupling climbed, but that's the ~{$temp} degree heat talking, not your aerobic base slipping."
            : "Decoupling drifted up, your aerobic base isn't quite solid yet.";
    }

    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
     */
    private function appendSplitClaim(StreamSummary $summary, array &$claims): void
    {
        if ($summary->negativeSplit() === true) {
            $claims[] = [
                'anchor' => 'metric:negative_split',
                'text' => 'Second half came in faster than the first, a clean negative split.',
                'value' => null,
                'delta' => null,
            ];

            return;
        }

        $fastest = $this->fastestSplit($summary);
        if ($fastest !== null) {
            $claims[] = [
                'anchor' => "split:{$fastest['km']}",
                'text' => "Km {$fastest['km']} was the fastest of the run.",
                'value' => $fastest['pace'],
                'delta' => null,
            ];
        }
    }

    /**
     * @return array{km: int, pace: string}|null
     */
    private function fastestSplit(StreamSummary $summary): ?array
    {
        /** @var array<int, array{km?: int, pace?: string}> $perKm */
        $perKm = $summary->perKm() ?? [];
        if (count($perKm) < 3) {
            return null;
        }

        $paces = [];
        foreach ($perKm as $row) {
            $km = $row['km'] ?? null;
            if (! is_int($km)) {
                continue;
            }

            $seconds = $this->parsePaceToSeconds(is_string($row['pace'] ?? null) ? $row['pace'] : '');
            if ($seconds !== null) {
                $paces[$km] = $seconds;
            }
        }
        if (count($paces) < 3) {
            return null;
        }

        $fastestKm = (int) array_keys($paces, min($paces), true)[0];
        $index = array_search($fastestKm, array_column($perKm, 'km'), true);
        $pace = $index !== false ? ($perKm[$index]['pace'] ?? null) : null;

        return is_string($pace) ? ['km' => $fastestKm, 'pace' => $pace] : null;
    }

    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
     */
    private function appendGradeClaim(StreamSummary $summary, array &$claims): void
    {
        $grade = $summary->maxGradePct();
        if ($grade === null || $grade < self::NOTABLE_GRADE_PCT) {
            return;
        }

        $claims[] = [
            'anchor' => 'metric:grade',
            'text' => "The steepest stretch of this run was a real climb, worth the extra effort it took.",
            'value' => DecimalFormatter::trimmed($grade).'%',
            'delta' => null,
        ];
    }

    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
     */
    private function appendZoneClaim(StreamSummary $summary, array &$claims): void
    {
        $zonePct = $this->resolveZonePercentages($summary);
        if ($zonePct === []) {
            return;
        }

        $dominantZone = array_keys($zonePct, max($zonePct), true)[0] ?? null;
        if (! is_string($dominantZone)) {
            return;
        }

        $dominantPct = (float) $zonePct[$dominantZone];
        if ($dominantPct < self::DOMINANT_ZONE_PCT) {
            return;
        }

        $claims[] = [
            'anchor' => 'zone:'.strtolower($dominantZone),
            'text' => "Most of this run sat in {$dominantZone}.",
            'value' => DecimalFormatter::trimmed($dominantPct).'%',
            'delta' => null,
        ];
    }

    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
     */
    private function appendPaceVariabilityClaim(StreamSummary $summary, array &$claims): void
    {
        $raw = $summary->paceVariabilitySec();
        if ($raw === null || ! PaceConsistency::isNotablyUneven($raw)) {
            return;
        }

        $claims[] = [
            'anchor' => 'metric:pace_variability',
            'text' => 'Pace varied a fair bit through this run, worth aiming for more consistency next time.',
            'value' => null,
            'delta' => null,
        ];
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
