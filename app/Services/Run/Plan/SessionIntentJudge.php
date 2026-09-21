<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Services\Run\Ingest\KmSplitBuilder;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\HeartRateZones;
use App\Services\Run\Metrics\IntervalDetector;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Whether a graded day's runs did what its session was written for, measured
 * on pace against the prescription with heart rate able to rescue a slow
 * block. See `docs/decisions/a-day-is-graded-on-distance-and-intent.md`.
 */
final class SessionIntentJudge
{
    public const int PACE_TOLERANCE_SEC = 10;

    /**
     * @param  list<SessionSegment>  $segments  the effective session's prescription
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param  list<ActivityDetail>  $runs  every run logged on the day
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    public static function judge(SessionType $sessionType, array $segments, ?array $paces, array $runs): array
    {
        if ($paces === null || $runs === []) {
            return self::reading(IntentVerdict::Unknown);
        }

        return match ($sessionType) {
            SessionType::Tempo => self::tempo($segments, $runs),
            SessionType::Interval => self::interval($segments, $runs),
            SessionType::Long => self::hasHardBlock($segments) ? self::tempo($segments, $runs) : self::steady($segments, $paces, $runs),
            SessionType::Easy => self::steady($segments, $paces, $runs),
            SessionType::Rest, SessionType::Race => self::reading(IntentVerdict::Unknown),
        };
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function tempo(array $segments, array $runs): array
    {
        $blocks = array_values(array_filter($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Main && in_array($segment->paceLabel, [PaceBand::Threshold, PaceBand::Marathon], true)));
        $block = $blocks[0] ?? null;
        if ($block?->minutes === null || $block->paceSecPerKm === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $summary = self::summaryOf(self::longest($runs));
        $totalMinutes = array_sum(array_map(static fn (SessionSegment $segment): float => $segment->minutes ?? 0.0, $blocks));
        $longestMinutes = 0.0;
        foreach ($blocks as $candidate) {
            $longestMinutes = max($longestMinutes, $candidate->minutes ?? 0.0);
        }
        $evidence = ['block_minutes' => $totalMinutes, 'target_pace_sec' => $block->paceSecPerKm, 'tolerance_sec' => self::PACE_TOLERANCE_SEC];

        [$window, $windowPace] = self::bestWindow($summary, $longestMinutes);
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
            if ($windowPace <= $block->paceSecPerKm + self::PACE_TOLERANCE_SEC) {
                return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace']);
            }
        }

        return self::onHeartRate($summary, $block->zone, $totalMinutes, $windowPace !== null, $evidence);
    }

    /** @param list<SessionSegment> $segments */
    private static function hasHardBlock(array $segments): bool
    {
        return array_any($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Main && $segment->paceLabel !== PaceBand::Easy);
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function interval(array $segments, array $runs): array
    {
        $reps = array_values(array_filter($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Interval));
        $rep = $reps[0] ?? null;
        $repMinutes = $rep?->minutes;
        $repPace = $rep?->paceSecPerKm;
        if ($rep === null || $repMinutes === null || $repPace === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $ceiling = $repPace + self::PACE_TOLERANCE_SEC;
        $needed = max(1, count($reps) - 1);
        $summary = self::summaryOf(self::longest($runs));
        $evidence = [
            'reps_prescribed' => count($reps),
            'reps_needed' => $needed,
            'rep_minutes' => $repMinutes,
            'target_pace_sec' => $repPace,
            'tolerance_sec' => self::PACE_TOLERANCE_SEC,
        ];

        $lapPaces = self::lapPaces($summary->laps() ?? []);
        $work = IntervalDetector::detect($lapPaces);
        if ($work !== []) {
            $atPace = count(array_filter($work, static fn (int $position): bool => $lapPaces[$position] <= $ceiling));
            $evidence['reps_at_pace'] = $atPace;
            if ($atPace >= $needed) {
                return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'laps']);
            }

            return self::onHeartRate($summary, $rep->zone, $needed * $repMinutes, true, $evidence);
        }

        [$window, $windowPace] = self::bestWindow($summary, $repMinutes);
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
            if ($windowPace <= $ceiling) {
                return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'window']);
            }
        }

        return self::onHeartRate($summary, $rep->zone, $needed * $repMinutes, $windowPace !== null, $evidence);
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}  $paces
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function steady(array $segments, array $paces, array $runs): array
    {
        $main = $segments[0] ?? null;
        $pace = self::dayPace($runs);
        if ($main === null || $pace === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $ceiling = $paces['marathon'] - ($main->paceLabel === PaceBand::Marathon ? self::PACE_TOLERANCE_SEC : 0);
        $evidence = ['pace_sec' => $pace, 'ceiling_pace_sec' => $ceiling];
        if ($pace >= $ceiling) {
            return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace']);
        }

        $total = 0.0;
        $above = 0.0;
        foreach ($runs as $run) {
            foreach (self::summaryOf($run)->zoneMinutes() ?? [] as $zone => $minutes) {
                $total += (float) $minutes;
                $above += self::zoneIndex((string) $zone) > self::zoneIndex($main->zone) ? (float) $minutes : 0.0;
            }
        }
        if ($total <= 0.0) {
            return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace']);
        }

        $share = $above / $total;
        $evidence += ['basis' => 'heart_rate', 'zone' => $main->zone, 'above_zone_pct' => (int) round($share * 100)];

        return self::reading($share <= PlanAdapter::EASY_DAY_HARD_SHARE ? IntentVerdict::Hit : IntentVerdict::TooHard, $evidence);
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function onHeartRate(StreamSummary $summary, string $zone, float $minutesNeeded, bool $paceRead, array $evidence): array
    {
        $zoneMinutes = $summary->zoneMinutes();
        if ($zoneMinutes === null) {
            return self::reading($paceRead ? IntentVerdict::Missed : IntentVerdict::Unknown, $evidence + ['basis' => 'pace']);
        }

        $minutes = 0.0;
        foreach ($zoneMinutes as $key => $value) {
            $minutes += self::zoneIndex((string) $key) >= self::zoneIndex($zone) ? (float) $value : 0.0;
        }

        return self::reading(
            $minutes >= $minutesNeeded ? IntentVerdict::Hit : IntentVerdict::Missed,
            $evidence + ['basis' => 'heart_rate', 'zone' => $zone, 'zone_minutes' => round($minutes, 1)],
        );
    }

    /** @return array{0: string|null, 1: int|null} */
    private static function bestWindow(StreamSummary $summary, float $minutes): array
    {
        $label = null;
        foreach (StreamAnalysis::BEST_EFFORT_WINDOWS as $seconds => $candidate) {
            if ($seconds <= $minutes * 60) {
                $label = $candidate;
            }
        }
        if ($label === null) {
            return [null, null];
        }

        $pace = $summary->bestPace($label);
        $seconds = $pace === null ? null : PaceFormatter::parse($pace);

        return [$label, $seconds === null ? null : (int) round($seconds)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $laps
     * @return array<int, float>
     */
    private static function lapPaces(array $laps): array
    {
        $distances = array_map(static fn (array $lap): float => is_numeric($lap['distance_m'] ?? null) ? (float) $lap['distance_m'] : 0.0, $laps);
        if (KmSplitBuilder::isPlainKmGrid(array_values($distances))) {
            return [];
        }

        $paces = [];
        foreach ($laps as $position => $lap) {
            $pace = PaceCalculator::secPerKm($distances[$position], is_numeric($lap['elapsed_sec'] ?? null) ? (float) $lap['elapsed_sec'] : null);
            if ($pace !== null) {
                $paces[$position] = $pace;
            }
        }

        return $paces;
    }

    /** @param  non-empty-list<ActivityDetail>  $runs */
    private static function dayPace(array $runs): ?int
    {
        $seconds = 0.0;
        $km = 0.0;
        foreach ($runs as $run) {
            $moving = (float) ($run->moving_time ?? $run->elapsed_time ?? 0);
            $gap = self::summaryOf($run)->gapPace();
            $gapSec = $gap === null ? null : PaceFormatter::parse($gap);
            $seconds += $moving;
            $km += $gapSec !== null && $gapSec > 0.0 && $moving > 0.0 ? $moving / $gapSec : (float) $run->distance / 1000;
        }

        return $seconds > 0.0 && $km > 0.0 ? (int) round($seconds / $km) : null;
    }

    /** @param  non-empty-list<ActivityDetail>  $runs */
    private static function longest(array $runs): ActivityDetail
    {
        usort($runs, static fn (ActivityDetail $a, ActivityDetail $b): int => (float) $b->distance <=> (float) $a->distance);

        return $runs[0];
    }

    private static function summaryOf(ActivityDetail $run): StreamSummary
    {
        return StreamSummary::fromArray($run->streamSummary());
    }

    private static function zoneIndex(string $zone): int
    {
        $index = array_search($zone, HeartRateZones::KEYS, true);

        return $index === false ? -1 : $index;
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function reading(IntentVerdict $verdict, array $evidence = []): array
    {
        return ['verdict' => $verdict, 'evidence' => $evidence];
    }
}
