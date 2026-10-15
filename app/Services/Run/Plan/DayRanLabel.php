<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Services\Run\Metrics\PaceFormatter;

/**
 * What a judged day ran, in the terms its ask is written in: the blocks or
 * reps a quality day asked for, heart rate on an easy or long day, the whole
 * effort on a time trial or race. Read off the judge's stored evidence, so
 * the card never sets a whole-run average against a block's target. See
 * `docs/decisions/a-quality-day-is-judged-on-every-block.md`.
 */
final class DayRanLabel
{
    /**
     * @param  array<string, int|float|string>  $evidence
     * @param  list<SessionSegment>  $segments  the day's shown session
     * @param  list<array{km: float, seconds: int|null, avg_hr?: int|null}>  $runs  the day's runs
     */
    public static function of(SessionType $type, bool $timeTrial, array $evidence, ?int $ranPaceSec, array $segments, array $runs): ?string
    {
        $ranPace = $ranPaceSec === null ? null : self::pace($ranPaceSec);
        if ($timeTrial || $type === SessionType::Race) {
            return $ranPace;
        }

        $quality = isset($evidence['reps_prescribed']) || isset($evidence['block_minutes']);

        return match (true) {
            $quality && ($evidence['basis'] ?? null) === 'heart_rate' && isset($evidence['zone']) && is_numeric($evidence['zone_minutes'] ?? null) => (int) round((float) $evidence['zone_minutes'])." min in {$evidence['zone']}+",
            is_numeric($evidence['reps_at_pace'] ?? null) && is_numeric($evidence['reps_prescribed'] ?? null) => "{$evidence['reps_at_pace']} of {$evidence['reps_prescribed']} reps at pace"
                .(is_numeric($evidence['rep_pace_sec'] ?? null) ? ', avg '.self::pace((int) $evidence['rep_pace_sec']) : ''),
            is_numeric($evidence['block_pace_sec'] ?? null) && $type === SessionType::Long => self::longBlock((int) $evidence['block_pace_sec'], $evidence, $segments),
            is_numeric($evidence['block_pace_sec'] ?? null) => self::blocks((int) $evidence['block_pace_sec'], $evidence),
            isset($evidence['window']) && is_numeric($evidence['window_pace_sec'] ?? null) => 'best '.self::windowWords((string) $evidence['window']).' at '.self::pace((int) $evidence['window_pace_sec']),
            in_array($type, [SessionType::Easy, SessionType::Long], true) => self::steady($ranPace, $runs),
            default => $ranPace,
        };
    }

    /** @param  array<string, int|float|string>  $evidence */
    private static function blocks(int $paceSec, array $evidence): string
    {
        $count = max(1, (int) ($evidence['blocks_prescribed'] ?? 1));
        $minutes = self::minutes((float) $evidence['block_minutes'] / $count);

        return ($count > 1 ? "{$count} × {$minutes} min" : "{$minutes} min").' at '.self::pace($paceSec);
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @param  list<SessionSegment>  $segments
     */
    private static function longBlock(int $paceSec, array $evidence, array $segments): string
    {
        $km = array_sum(array_map(static fn (SessionSegment $segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : (float) ($segment->km ?? 0.0), $segments));
        $label = ($km > 0.0 ? self::minutes($km).' km' : self::minutes((float) $evidence['block_minutes']).' min').' at '.self::pace($paceSec);
        if (! is_numeric($evidence['easy_over_cap_minutes'] ?? null)) {
            return $label;
        }

        $over = (float) $evidence['easy_over_cap_minutes'];

        return $label.' · easy part '.($over >= 1.0 ? (int) round($over).' min over cap' : 'under cap');
    }

    /** @param  list<array{km: float, seconds: int|null, avg_hr?: int|null}>  $runs */
    private static function steady(?string $ranPace, array $runs): ?string
    {
        $beats = 0.0;
        $seconds = 0;
        foreach ($runs as $run) {
            $heartRate = $run['avg_hr'] ?? null;
            if ($heartRate !== null && $run['seconds'] !== null) {
                $beats += $heartRate * $run['seconds'];
                $seconds += $run['seconds'];
            }
        }
        if ($seconds === 0) {
            return $ranPace;
        }

        $average = 'avg '.(int) round($beats / $seconds).' bpm';

        return $ranPace === null ? $average : "{$average} · {$ranPace}";
    }

    private static function windowWords(string $window): string
    {
        return preg_match('/^(\d+)(min|s)$/', $window, $match) === 1 ? $match[1].($match[2] === 'min' ? ' min' : ' s') : $window;
    }

    private static function minutes(float $value): string
    {
        return (string) round($value, 1);
    }

    private static function pace(int $secPerKm): string
    {
        return PaceFormatter::format((float) $secPerKm).'/km';
    }
}
