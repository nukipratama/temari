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

    /** A quality block quicker than its target by more than this share of the target pace is excessive, not controlled. */
    public const float EXCESSIVE_FRACTION = 0.05;

    /** The share of a requested block or rep a window, lap or recording must cover before it can prove the work was done. */
    public const float MIN_COVERAGE = 0.9;

    /**
     * @param  list<SessionSegment>  $segments  the effective session's prescription
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @param  list<ActivityDetail>  $runs  every run logged on the day
     * @param  bool  $heartRateCapped  whether the athlete's zones are their own, so easy effort is capped by heart rate
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    public static function judge(SessionType $sessionType, array $segments, ?array $paces, array $runs, bool $heartRateCapped = false): array
    {
        if ($paces === null || $runs === []) {
            return self::reading(IntentVerdict::Unknown);
        }

        return match ($sessionType) {
            SessionType::Tempo, SessionType::Interval => array_any($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Interval)
                ? self::interval($segments, $runs)
                : self::tempo($segments, $runs),
            SessionType::Long => self::hasHardBlock($segments) && count($segments) > 1
                ? self::withEasyParts(self::tempo($segments, $runs), $segments, $runs, $heartRateCapped)
                : self::steady($segments, $paces, $runs, $heartRateCapped),
            SessionType::Easy => self::steady($segments, $paces, $runs, $heartRateCapped),
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

        $anchor = self::longest($runs);
        $summary = self::summaryOf($anchor);
        $totalMinutes = array_sum(array_map(static fn (SessionSegment $segment): float => $segment->minutes ?? 0.0, $blocks));
        $longestMinutes = 0.0;
        foreach ($blocks as $candidate) {
            $longestMinutes = max($longestMinutes, $candidate->minutes ?? 0.0);
        }
        $evidence = ['block_minutes' => $totalMinutes, 'target_pace_sec' => $block->paceSecPerKm, 'tolerance_sec' => self::PACE_TOLERANCE_SEC];

        if (self::cannotCover($runs, $anchor, $longestMinutes)) {
            return self::unknownWithHeartRate($evidence, $runs, $block->zone, 'tempo');
        }

        [$window, $windowPace, $windowSeconds] = self::bestWindow($summary, $longestMinutes);
        $coveredSeconds = $windowSeconds !== null && $windowSeconds >= $longestMinutes * 60 * self::MIN_COVERAGE ? $windowSeconds : null;
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
        }
        if ($coveredSeconds !== null && $windowPace !== null) {
            $stimulus = self::stimulus('tempo', $coveredSeconds / 60, 'window');
            if ($windowPace < $block->paceSecPerKm * (1 - self::EXCESSIVE_FRACTION)) {
                return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace', 'control' => 'excessive'] + $stimulus);
            }
            if ($windowPace <= $block->paceSecPerKm + self::PACE_TOLERANCE_SEC) {
                return $coveredSeconds >= $totalMinutes * 60 * self::MIN_COVERAGE
                    ? self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace', 'control' => 'controlled'] + $stimulus)
                    : self::onHeartRateOrUnknown($summary, $runs, $block->zone, $totalMinutes, $evidence, ['basis' => 'pace'] + $stimulus, 'tempo');
            }
        }

        return self::onHeartRate($summary, $runs, $block->zone, $totalMinutes, $coveredSeconds !== null && $windowPace !== null, $evidence, 'tempo', 'window');
    }

    /** @param list<SessionSegment> $segments */
    private static function hasHardBlock(array $segments): bool
    {
        return array_any($segments, static fn (SessionSegment $segment): bool => $segment->paceLabel !== PaceBand::Easy);
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
        $anchor = self::longest($runs);
        $summary = self::summaryOf($anchor);
        $evidence = [
            'reps_prescribed' => count($reps),
            'reps_needed' => $needed,
            'rep_minutes' => $repMinutes,
            'target_pace_sec' => $repPace,
            'tolerance_sec' => self::PACE_TOLERANCE_SEC,
        ];

        if (self::cannotCover($runs, $anchor, $needed * $repMinutes)) {
            return self::unknownWithHeartRate($evidence, $runs, $rep->zone, 'interval');
        }

        $laps = $summary->laps() ?? [];
        $lapPaces = self::lapPaces($laps);
        $work = IntervalDetector::detect($lapPaces);
        if ($work !== []) {
            $counted = array_values(array_filter($work, static fn (int $position): bool => $lapPaces[$position] <= $ceiling
                && is_numeric($laps[$position]['elapsed_sec'] ?? null)
                && (float) $laps[$position]['elapsed_sec'] >= $repMinutes * 60 * self::MIN_COVERAGE));
            $evidence['reps_at_pace'] = count($counted);
            if (count($counted) >= $needed) {
                $meanPace = array_sum(array_map(static fn (int $position): float => $lapPaces[$position], $counted)) / count($counted);
                $stimulus = self::stimulus('interval', count($counted) * $repMinutes, 'laps');
                if ($meanPace < $repPace * (1 - self::EXCESSIVE_FRACTION)) {
                    return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'laps', 'control' => 'excessive'] + $stimulus);
                }

                return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'laps', 'control' => 'controlled'] + $stimulus);
            }

            return self::onHeartRate($summary, $runs, $rep->zone, $needed * $repMinutes, true, $evidence, 'interval', 'laps');
        }

        [$window, $windowPace, $windowSeconds] = self::bestWindow($summary, $repMinutes);
        $coveredSeconds = $windowSeconds !== null && $windowSeconds >= $repMinutes * 60 * self::MIN_COVERAGE ? $windowSeconds : null;
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
        }
        if ($coveredSeconds !== null && $windowPace !== null) {
            $stimulus = self::stimulus('interval', $coveredSeconds / 60, 'window');
            if ($windowPace < $repPace * (1 - self::EXCESSIVE_FRACTION)) {
                return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'window', 'control' => 'excessive'] + $stimulus);
            }
            if ($windowPace <= $ceiling) {
                return $coveredSeconds >= $needed * $repMinutes * 60 * self::MIN_COVERAGE
                    ? self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'window', 'control' => 'controlled'] + $stimulus)
                    : self::onHeartRateOrUnknown($summary, $runs, $rep->zone, $needed * $repMinutes, $evidence, ['basis' => 'window'] + $stimulus, 'interval');
            }
        }

        return self::onHeartRate($summary, $runs, $rep->zone, $needed * $repMinutes, $coveredSeconds !== null && $windowPace !== null, $evidence, 'interval', 'window');
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}  $paces
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function steady(array $segments, array $paces, array $runs, bool $heartRateCapped): array
    {
        $main = $segments[0] ?? null;
        $pace = self::dayPace($runs);
        if ($main === null || $pace === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $marathon = $main->paceLabel === PaceBand::Marathon;
        $ceiling = $paces['marathon'] - ($marathon ? self::PACE_TOLERANCE_SEC : 0);
        $evidence = ['pace_sec' => $pace, 'ceiling_pace_sec' => $ceiling];
        if ($marathon) {
            $evidence['limit'] = 'marathon';
        }
        $effort = $marathon ? null : EasyEffort::of($runs);
        if ($heartRateCapped && $effort !== null) {
            return self::onEasyCap($effort, $evidence);
        }
        if ($pace >= $ceiling) {
            return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace'] + self::stimulus('easy', null, 'pace'));
        }

        return $effort === null
            ? self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace'] + self::stimulus('hard', null, 'pace'))
            : self::onEasyCap($effort, $evidence);
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function onEasyCap(EasyEffort $effort, array $evidence): array
    {
        $evidence += ['basis' => 'heart_rate', 'hr_cap_bpm' => $effort->capBpm, 'over_cap_minutes' => $effort->overCapMinutes(), 'over_cap_limit_minutes' => $effort->limitMinutes()];

        return $effort->tooHard()
            ? self::reading(IntentVerdict::TooHard, $evidence + self::stimulus('hard', $effort->overCapMinutes(), 'heart_rate'))
            : self::reading(IntentVerdict::Hit, $evidence + self::stimulus('easy', null, 'heart_rate'));
    }

    /**
     * A marathon-pace long run keeps its block's pace verdict, while the easy
     * running around the block is held to the heart-rate cap; the block's own
     * prescribed minutes are taken off the time over the cap first.
     *
     * @param  array{verdict: IntentVerdict, evidence: array<string, int|float|string>}  $block
     * @param  list<SessionSegment>  $segments
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function withEasyParts(array $block, array $segments, array $runs, bool $heartRateCapped): array
    {
        $blockSeconds = 60 * array_sum(array_map(static fn (SessionSegment $segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : ($segment->minutes ?? 0.0), $segments));
        $effort = $heartRateCapped ? EasyEffort::of($runs, $blockSeconds) : null;
        if ($effort === null) {
            return $block;
        }

        $evidence = $block['evidence'] + ['hr_cap_bpm' => $effort->capBpm, 'easy_over_cap_minutes' => $effort->overCapMinutes(), 'easy_parts' => $effort->tooHard() ? 'too_hard' : 'held', 'block_verdict' => $block['verdict']->value];

        return self::reading($effort->tooHard() ? IntentVerdict::TooHard : $block['verdict'], $evidence);
    }

    /**
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function onHeartRate(StreamSummary $summary, array $runs, string $zone, float $minutesNeeded, bool $paceRead, array $evidence, string $family, string $paceSource): array
    {
        $zoneMinutes = $summary->zoneMinutes();
        if ($zoneMinutes === null) {
            return self::reading(
                $paceRead ? IntentVerdict::Missed : IntentVerdict::Unknown,
                $evidence + ['basis' => 'pace'] + ($paceRead ? self::stimulus('easy', null, $paceSource) : self::stimulus('unknown', null, 'none')),
            );
        }

        $minutes = self::minutesAtOrAbove($zoneMinutes, $zone);
        $dayMinutes = self::dayMinutesAtOrAbove($runs, $zone) ?? $minutes;

        return self::reading(
            $minutes >= $minutesNeeded ? IntentVerdict::Hit : IntentVerdict::Missed,
            $evidence + ['basis' => 'heart_rate', 'zone' => $zone, 'zone_minutes' => round($minutes, 1)]
                + self::stimulus($dayMinutes > 0.0 ? $family : 'easy', $dayMinutes > 0.0 ? $dayMinutes : null, 'heart_rate'),
        );
    }

    /**
     * A window at pace that covers too little of the requested work: heart
     * rate can prove the rest, and nothing here can disprove it.
     *
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<string, int|float|string>  $evidence
     * @param  array<string, int|float|string>  $windowReading  the window's basis and stimulus
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function onHeartRateOrUnknown(StreamSummary $summary, array $runs, string $zone, float $minutesNeeded, array $evidence, array $windowReading, string $family): array
    {
        if ($summary->zoneMinutes() === null) {
            return self::reading(IntentVerdict::Unknown, $evidence + $windowReading);
        }

        $reading = self::onHeartRate($summary, $runs, $zone, $minutesNeeded, true, $evidence, $family, 'window');

        return $reading['verdict'] === IntentVerdict::Hit ? $reading : self::reading(IntentVerdict::Unknown, $reading['evidence']);
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @param  non-empty-list<ActivityDetail>  $runs
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function unknownWithHeartRate(array $evidence, array $runs, string $zone, string $family): array
    {
        $minutes = self::dayMinutesAtOrAbove($runs, $zone);

        return self::reading(IntentVerdict::Unknown, $evidence + ($minutes === null || $minutes <= 0.0
            ? self::stimulus('unknown', null, 'none')
            : self::stimulus($family, $minutes, 'heart_rate')));
    }

    /**
     * A day of several recordings cannot prove a block when none of them is long enough to hold it.
     *
     * @param  non-empty-list<ActivityDetail>  $runs
     */
    private static function cannotCover(array $runs, ActivityDetail $anchor, float $requestedMinutes): bool
    {
        return count($runs) > 1 && (float) ($anchor->moving_time ?? $anchor->elapsed_time ?? 0) / 60 < $requestedMinutes * self::MIN_COVERAGE;
    }

    /**
     * @param  array<string, float|int>  $zoneMinutes
     */
    private static function minutesAtOrAbove(array $zoneMinutes, string $zone): float
    {
        $minutes = 0.0;
        foreach ($zoneMinutes as $key => $value) {
            $minutes += self::zoneIndex((string) $key) >= self::zoneIndex($zone) ? (float) $value : 0.0;
        }

        return $minutes;
    }

    /**
     * @param  non-empty-list<ActivityDetail>  $runs
     */
    private static function dayMinutesAtOrAbove(array $runs, string $zone): ?float
    {
        $minutes = null;
        foreach ($runs as $run) {
            $zoneMinutes = self::summaryOf($run)->zoneMinutes();
            if ($zoneMinutes !== null) {
                $minutes = ($minutes ?? 0.0) + self::minutesAtOrAbove($zoneMinutes, $zone);
            }
        }

        return $minutes;
    }

    /** @return array<string, int|float|string> */
    private static function stimulus(string $family, ?float $minutes, string $source): array
    {
        return ['stimulus_family' => $family, 'stimulus_source' => $source]
            + ($minutes === null ? [] : ['stimulus_minutes' => round($minutes, 1)]);
    }

    /** @return array{0: string|null, 1: int|null, 2: int|null} label, pace sec/km and length in seconds of the longest window that fits inside the block */
    private static function bestWindow(StreamSummary $summary, float $minutes): array
    {
        $label = null;
        $length = null;
        foreach (StreamAnalysis::BEST_EFFORT_WINDOWS as $seconds => $candidate) {
            if ($seconds <= $minutes * 60) {
                $label = $candidate;
                $length = $seconds;
            }
        }
        if ($label === null) {
            return [null, null, null];
        }

        $pace = $summary->bestPace($label);
        $seconds = $pace === null ? null : PaceFormatter::parse($pace);

        return [$label, $seconds === null ? null : (int) round($seconds), $length];
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
