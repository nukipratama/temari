<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\HeartRateZones;
use App\Services\Run\Metrics\IntervalDetector;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\WorkBlocks;

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
     * @param  array<int, array<string, mixed>>  $streams  raw Strava streams dicts, keyed by activity id
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    public static function judge(SessionType $sessionType, array $segments, ?array $paces, array $runs, bool $heartRateCapped = false, array $streams = []): array
    {
        if ($paces === null || $runs === []) {
            return self::reading(IntentVerdict::Unknown);
        }

        return match ($sessionType) {
            SessionType::Tempo, SessionType::Interval => array_any($segments, static fn (SessionSegment $segment): bool => $segment->key === SegmentKey::Interval)
                ? self::interval($segments, $runs, $paces['easy'], $streams)
                : self::tempo($segments, $runs, $paces['easy'], $streams),
            SessionType::Long => self::hasHardBlock($segments) && count($segments) > 1
                ? self::withEasyParts(self::tempo($segments, $runs, $paces['easy'], $streams), $segments, $runs, $heartRateCapped)
                : self::steady($segments, $paces, $runs, $heartRateCapped),
            SessionType::Easy => self::steady($segments, $paces, $runs, $heartRateCapped),
            SessionType::Rest, SessionType::Race => self::reading(IntentVerdict::Unknown),
        };
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<int, array<string, mixed>>  $streams
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function tempo(array $segments, array $runs, int $easyPaceSec, array $streams): array
    {
        $blocks = self::workUnits($segments, SegmentKey::Main);
        if ($blocks === []) {
            return self::reading(IntentVerdict::Unknown);
        }
        $block = $blocks[0];
        if ($block->minutes === null || $block->paceSecPerKm === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $blockPaces = [$block->paceSecPerKm, ...array_map(static fn (SessionSegment $segment): int => $segment->paceSecPerKm ?? $block->paceSecPerKm, $blocks)];
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

        $measured = self::measured($anchor, $summary, $blocks, $easyPaceSec, $streams);
        if ($measured !== null) {
            return self::blocksReading($measured, $blocks, $summary, $runs, $block->zone, $totalMinutes, $evidence);
        }

        [$window, $windowPace, $windowSeconds] = self::bestWindow($summary, $longestMinutes);
        $coveredSeconds = $windowSeconds !== null && $windowSeconds >= $longestMinutes * 60 * self::MIN_COVERAGE ? $windowSeconds : null;
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
        }
        if ($coveredSeconds !== null && $windowPace !== null) {
            $stimulus = self::stimulus('tempo', $coveredSeconds / 60, 'window');
            if ($windowPace < min($blockPaces) * (1 - self::EXCESSIVE_FRACTION)) {
                return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace', 'control' => 'excessive'] + $stimulus);
            }
            if ($windowPace <= max($blockPaces) + self::PACE_TOLERANCE_SEC) {
                return $coveredSeconds >= $totalMinutes * 60 * self::MIN_COVERAGE
                    ? self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace', 'control' => 'controlled'] + $stimulus)
                    : self::onHeartRateOrUnknown($summary, $runs, $block->zone, $totalMinutes, $evidence, ['basis' => 'pace'] + $stimulus, 'tempo');
            }
        }

        return self::onHeartRate($summary, $runs, $block->zone, $totalMinutes, $coveredSeconds !== null && $windowPace !== null, $evidence, 'tempo', 'window');
    }

    /**
     * The prescribed blocks as run: off the laps when they line up with the
     * prescription, else the quickest non-overlapping block-length stretches
     * of the stream, else null.
     *
     * @param  non-empty-list<SessionSegment>  $blocks
     * @param  array<int, array<string, mixed>>  $streams
     * @return array{source: string, units: list<array{seconds: float, km: float}>}|null
     */
    private static function measured(ActivityDetail $anchor, StreamSummary $summary, array $blocks, int $easyPaceSec, array $streams): ?array
    {
        $prescribed = array_map(static fn (SessionSegment $block): array => [
            'seconds' => ($block->minutes ?? 0.0) * 60,
            'km' => $block->km ?? ($block->paceSecPerKm > 0 ? ($block->minutes ?? 0.0) * 60 / $block->paceSecPerKm : 0.0),
        ], $blocks);
        $slowest = max(array_map(static fn (SessionSegment $block): int => (int) $block->paceSecPerKm, $blocks));

        $fromLaps = WorkBlocks::fromLaps($summary->laps() ?? [], $prescribed, $slowest, $easyPaceSec);
        if ($fromLaps !== null) {
            return ['source' => 'laps', 'units' => $fromLaps];
        }

        $stream = $streams[$anchor->activity_id] ?? null;
        $fromStream = $stream === null ? null : WorkBlocks::fromStream($stream, array_map(static fn (array $block): int => (int) round($block['seconds']), $prescribed));

        return $fromStream === null ? null : ['source' => 'stream', 'units' => $fromStream];
    }

    /**
     * Every block measured, read the way reps are: all but one on pace (one
     * when only one or two were asked) did the work, unless their
     * time-weighted pace beat the target by more than
     * {@see self::EXCESSIVE_FRACTION}; short of that, heart rate decides.
     *
     * @param  array{source: string, units: list<array{seconds: float, km: float}>}  $measured
     * @param  non-empty-list<SessionSegment>  $blocks
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function blocksReading(array $measured, array $blocks, StreamSummary $summary, array $runs, string $zone, float $totalMinutes, array $evidence): array
    {
        $paces = [];
        $targets = [];
        $atPace = 0;
        $seconds = 0.0;
        $km = 0.0;
        $targetSeconds = 0.0;
        foreach ($measured['units'] as $index => $unit) {
            $pace = (int) round($unit['seconds'] / $unit['km']);
            $target = $blocks[$index]->paceSecPerKm ?? (int) $blocks[0]->paceSecPerKm;
            $paces[] = $pace;
            $targets[] = $target;
            $atPace += $pace <= $target + self::PACE_TOLERANCE_SEC ? 1 : 0;
            $seconds += $unit['seconds'];
            $km += $unit['km'];
            $targetSeconds += $target * $unit['km'];
        }
        $meanPace = (int) round($seconds / $km);
        $source = $measured['source'] === 'laps' ? 'laps' : 'window';
        $evidence += [
            'blocks_prescribed' => count($blocks),
            'blocks_at_pace' => $atPace,
            'block_paces' => implode(',', $paces),
            'block_targets' => implode(',', $targets),
            'block_pace_sec' => $meanPace,
            'block_source' => $measured['source'],
        ];

        if ($atPace < max(1, count($blocks) - 1)) {
            return self::onHeartRate($summary, $runs, $zone, $totalMinutes, true, $evidence, 'tempo', $source);
        }

        $stimulus = self::stimulus('tempo', $seconds / 60, $source);
        if ($meanPace < $targetSeconds / $km * (1 - self::EXCESSIVE_FRACTION)) {
            return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace', 'control' => 'excessive'] + $stimulus);
        }

        return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace', 'control' => 'controlled'] + $stimulus);
    }

    /**
     * Reps found in the stream when no laps mark them, read the same way lap
     * reps are. A stretch counts as a rep run at all when it was quicker than
     * halfway between rep and easy pace, and only those make the average.
     *
     * @param  list<array{seconds: float, km: float}>  $found
     * @param  callable(int): int  $targetOf
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<string, int|float|string>  $evidence
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function repsFromStream(array $found, callable $targetOf, float $workThreshold, int $needed, float $repMinutes, StreamSummary $summary, array $runs, string $zone, array $evidence): array
    {
        $paces = array_map(static fn (array $stretch): int => (int) round($stretch['seconds'] / $stretch['km']), $found);
        $run = array_values(array_filter($paces, static fn (int $pace): bool => $pace <= $workThreshold));
        $counted = array_filter($paces, static fn (int $pace, int $index): bool => $pace <= $targetOf($index) + self::PACE_TOLERANCE_SEC, ARRAY_FILTER_USE_BOTH);
        $evidence['reps_at_pace'] = count($counted);
        if ($run !== []) {
            $evidence['rep_pace_sec'] = (int) round(array_sum($run) / count($run));
        }

        if (count($counted) < $needed) {
            return self::onHeartRate($summary, $runs, $zone, $needed * $repMinutes, true, $evidence, 'interval', 'window');
        }

        $meanPace = array_sum($counted) / count($counted);
        $meanTarget = array_sum(array_map($targetOf, array_keys($counted))) / count($counted);
        $stimulus = self::stimulus('interval', count($counted) * $repMinutes, 'window');
        if ($meanPace < $meanTarget * (1 - self::EXCESSIVE_FRACTION)) {
            return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'window', 'control' => 'excessive'] + $stimulus);
        }

        return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'window', 'control' => 'controlled'] + $stimulus);
    }

    /**
     * The work of one key, where back-to-back segments with no recovery between
     * them are one rep or block at their combined pace.
     *
     * @param  list<SessionSegment>  $segments
     * @return list<SessionSegment>
     */
    private static function workUnits(array $segments, SegmentKey $key): array
    {
        $units = [];
        $joinsPrevious = false;
        foreach ($segments as $segment) {
            if ($segment->key !== $key || $segment->paceLabel === PaceBand::Easy) {
                $joinsPrevious = false;

                continue;
            }
            $previous = $joinsPrevious ? array_pop($units) : null;
            $units[] = $previous === null ? $segment : self::joined($previous, $segment);
            $joinsPrevious = true;
        }

        return $units;
    }

    private static function joined(SessionSegment $first, SessionSegment $second): SessionSegment
    {
        if ($first->minutes === null || $second->minutes === null || $first->paceSecPerKm === null || $second->paceSecPerKm === null) {
            return $first;
        }
        $minutes = $first->minutes + $second->minutes;
        $km = $first->minutes * 60 / $first->paceSecPerKm + $second->minutes * 60 / $second->paceSecPerKm;

        return new SessionSegment($first->key, $minutes, $first->zone, $first->paceLabel, (int) round($minutes * 60 / $km), round($km, 1));
    }

    /** @param list<SessionSegment> $segments */
    private static function hasHardBlock(array $segments): bool
    {
        return array_any($segments, static fn (SessionSegment $segment): bool => $segment->paceLabel !== PaceBand::Easy);
    }

    /**
     * @param  list<SessionSegment>  $segments
     * @param  non-empty-list<ActivityDetail>  $runs
     * @param  array<int, array<string, mixed>>  $streams
     * @return array{verdict: IntentVerdict, evidence: array<string, int|float|string>}
     */
    private static function interval(array $segments, array $runs, int $easyPaceSec, array $streams): array
    {
        $reps = self::workUnits($segments, SegmentKey::Interval);
        if ($reps === []) {
            return self::reading(IntentVerdict::Unknown);
        }
        $rep = $reps[0];
        $repMinutes = $rep->minutes;
        $repPace = $rep->paceSecPerKm;
        if ($repMinutes === null || $repPace === null) {
            return self::reading(IntentVerdict::Unknown);
        }

        $repPaces = array_map(static fn (SessionSegment $segment): int => $segment->paceSecPerKm ?? $repPace, $reps);
        $paceRange = [$repPace, ...$repPaces];
        $ceiling = max($paceRange) + self::PACE_TOLERANCE_SEC;
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

        $targetOf = static fn (int $index): int => $repPaces[min($index, count($repPaces) - 1)];
        $laps = $summary->laps() ?? [];
        $lapPaces = IntervalDetector::lapPaces($laps);
        $work = IntervalDetector::detect($lapPaces);
        if ($work !== []) {
            $evidence['rep_pace_sec'] = (int) round(array_sum(array_map(static fn (int $position): float => $lapPaces[$position], $work)) / count($work));
            $counted = array_filter($work, static fn (int $position, int $index): bool => $lapPaces[$position] <= $targetOf($index) + self::PACE_TOLERANCE_SEC
                && is_numeric($laps[$position]['elapsed_sec'] ?? null)
                && (float) $laps[$position]['elapsed_sec'] >= $repMinutes * 60 * self::MIN_COVERAGE, ARRAY_FILTER_USE_BOTH);
            $evidence['reps_at_pace'] = count($counted);
            if (count($counted) >= $needed) {
                $meanPace = array_sum(array_map(static fn (int $position): float => $lapPaces[$position], $counted)) / count($counted);
                $meanTarget = array_sum(array_map($targetOf, array_keys($counted))) / count($counted);
                $stimulus = self::stimulus('interval', count($counted) * $repMinutes, 'laps');
                if ($meanPace < $meanTarget * (1 - self::EXCESSIVE_FRACTION)) {
                    return self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'laps', 'control' => 'excessive'] + $stimulus);
                }

                return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'laps', 'control' => 'controlled'] + $stimulus);
            }

            return self::onHeartRate($summary, $runs, $rep->zone, $needed * $repMinutes, true, $evidence, 'interval', 'laps');
        }

        $stream = $streams[$anchor->activity_id] ?? null;
        $found = $stream === null ? null : WorkBlocks::fromStream($stream, array_map(static fn (SessionSegment $segment): int => (int) round(($segment->minutes ?? $repMinutes) * 60), $reps));
        if ($found !== null) {
            return self::repsFromStream($found, $targetOf, (max($repPaces) + $easyPaceSec) / 2, $needed, $repMinutes, $summary, $runs, $rep->zone, $evidence);
        }

        [$window, $windowPace, $windowSeconds] = self::bestWindow($summary, $repMinutes);
        $coveredSeconds = $windowSeconds !== null && $windowSeconds >= $repMinutes * 60 * self::MIN_COVERAGE ? $windowSeconds : null;
        if ($window !== null && $windowPace !== null) {
            $evidence += ['window' => $window, 'window_pace_sec' => $windowPace];
        }
        if ($coveredSeconds !== null && $windowPace !== null) {
            $stimulus = self::stimulus('interval', $coveredSeconds / 60, 'window');
            if ($windowPace < min($paceRange) * (1 - self::EXCESSIVE_FRACTION)) {
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
        if ($heartRateCapped && $effort instanceof EasyEffort) {
            return self::onEasyCap($effort, $evidence);
        }
        if ($pace >= $ceiling) {
            return self::reading(IntentVerdict::Hit, $evidence + ['basis' => 'pace'] + self::stimulus('easy', null, 'pace'));
        }

        return $effort instanceof EasyEffort
            ? self::onEasyCap($effort, $evidence)
            : self::reading(IntentVerdict::TooHard, $evidence + ['basis' => 'pace'] + self::stimulus('hard', null, 'pace'));
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
        if (!$effort instanceof EasyEffort) {
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
