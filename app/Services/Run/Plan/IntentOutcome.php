<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Services\Run\Metrics\PaceFormatter;

/**
 * A persisted {@see SessionIntentJudge} verdict in plain words, with every
 * comparison already made: a reader is never handed a pace and a limit and
 * left to work out which side of it the run landed on.
 */
final class IntentOutcome
{
    /** The smallest gap between moving and effort-adjusted pace worth naming. */
    public const int EFFORT_ADJUSTED_MIN_GAP_SEC = 5;

    private const string STEADY = 'steady';

    private const string BLOCK = 'block';

    private const string REPS = 'reps';

    /**
     * The verdict as a clause that stands on its own.
     *
     * @param  array<string, int|float|string>  $evidence
     */
    public static function outcome(IntentVerdict $verdict, array $evidence): string
    {
        $family = self::family($evidence);

        return match ($verdict) {
            IntentVerdict::Hit => match ($family) {
                self::STEADY => 'it stayed at the easy effort the day asked for',
                self::BLOCK => 'the hard block got done at the effort it asked for',
                self::REPS => 'the reps got done at the effort they asked for',
                default => 'the session did the job it was written for',
            },
            IntentVerdict::Missed => $family === self::REPS
                ? 'the distance is there, but the reps never reached the effort they asked for'
                : 'the distance is there, but the hard effort the day asked for never showed up',
            IntentVerdict::TooHard => 'it ran harder than the easy effort the day asked for',
            IntentVerdict::Unknown => "this run's data can't tell how the effort went",
        };
    }

    /**
     * The numbers behind the verdict as one sentence, or null when the judge
     * recorded none worth saying.
     *
     * @param  array<string, int|float|string>  $evidence
     */
    public static function detail(IntentVerdict $verdict, array $evidence, ?int $ranPaceSec): ?string
    {
        if ($verdict === IntentVerdict::Unknown) {
            return null;
        }

        return match (self::family($evidence)) {
            self::STEADY => self::steadyDetail($verdict, $evidence, $ranPaceSec),
            self::BLOCK, self::REPS => self::qualityDetail($verdict, $evidence),
            default => null,
        };
    }

    /**
     * The pace the day's card shows, followed by the hill-adjusted pace the
     * judge graded on when the two differ by at least
     * {@see self::EFFORT_ADJUSTED_MIN_GAP_SEC}, or when they fall on opposite
     * sides of $ceilingSec so the moving pace alone would contradict the
     * comparison that follows.
     */
    public static function averaged(int $judgedPaceSec, ?int $ranPaceSec, ?int $ceilingSec = null): string
    {
        if ($ranPaceSec === null) {
            return 'averaged '.self::pace($judgedPaceSec);
        }

        $splitByCeiling = $ceilingSec !== null && ($ranPaceSec >= $ceilingSec) !== ($judgedPaceSec >= $ceilingSec);
        if (abs($judgedPaceSec - $ranPaceSec) < self::EFFORT_ADJUSTED_MIN_GAP_SEC && ! $splitByCeiling) {
            return 'averaged '.self::pace($ranPaceSec);
        }

        return 'averaged '.self::pace($ranPaceSec).'; effort-adjusted for hills that is '.self::pace($judgedPaceSec);
    }

    /** @param  array<string, int|float|string>  $evidence */
    private static function steadyDetail(IntentVerdict $verdict, array $evidence, ?int $ranPaceSec): ?string
    {
        if (! is_numeric($evidence['pace_sec'] ?? null) || ! is_numeric($evidence['ceiling_pace_sec'] ?? null)) {
            return null;
        }

        $judged = (int) $evidence['pace_sec'];
        $ceiling = (int) $evidence['ceiling_pace_sec'];
        $onHeartRate = ($evidence['basis'] ?? null) === 'heart_rate' && isset($evidence['zone'], $evidence['above_zone_pct']);

        return match (true) {
            $onHeartRate && $verdict === IntentVerdict::Hit => self::averaged($judged, $ranPaceSec)
                .", but heart rate kept it easy: only {$evidence['above_zone_pct']}% of the run went above {$evidence['zone']}",
            $onHeartRate => self::averaged($judged, $ranPaceSec)
                .", and {$evidence['above_zone_pct']}% of the run sat above {$evidence['zone']}",
            $verdict === IntentVerdict::TooHard => self::averaged($judged, $ranPaceSec, $ceiling)
                .', quicker than the easy limit of '.self::pace($ceiling),
            default => self::averaged($judged, $ranPaceSec),
        };
    }

    /** @param  array<string, int|float|string>  $evidence */
    private static function qualityDetail(IntentVerdict $verdict, array $evidence): ?string
    {
        $target = is_numeric($evidence['target_pace_sec'] ?? null) ? self::pace((int) $evidence['target_pace_sec']) : null;
        $targetName = self::family($evidence) === self::REPS ? 'rep pace' : 'target pace';
        $hit = $verdict === IntentVerdict::Hit;
        $parts = [];

        if (is_numeric($evidence['reps_at_pace'] ?? null) && $target !== null) {
            $parts[] = ($hit ? '' : 'only ')."{$evidence['reps_at_pace']} of {$evidence['reps_prescribed']} reps landed on the {$target} {$targetName}";
        } elseif (isset($evidence['window']) && is_numeric($evidence['window_pace_sec'] ?? null) && $target !== null) {
            $onPace = $hit && ($evidence['basis'] ?? null) !== 'heart_rate';
            $parts[] = 'best '.self::windowWords((string) $evidence['window']).' stretch averaged '.self::pace((int) $evidence['window_pace_sec'])
                .($onPace ? ", on the {$target} {$targetName}" : ", slower than the {$target} {$targetName}");
        }

        if (($evidence['basis'] ?? null) === 'heart_rate' && isset($evidence['zone']) && is_numeric($evidence['zone_minutes'] ?? null)) {
            $minutes = self::minutes((float) $evidence['zone_minutes']);
            $needed = self::minutes(self::minutesNeeded($evidence));
            $parts[] = $hit
                ? "heart rate spent {$minutes} minutes in {$evidence['zone']} or above, enough for the {$needed} minutes asked"
                : "heart rate spent only {$minutes} of the {$needed} minutes asked in {$evidence['zone']} or above";
        }

        return $parts === [] ? null : implode($hit ? ', but ' : ', and ', $parts);
    }

    /** @param  array<string, int|float|string>  $evidence */
    private static function minutesNeeded(array $evidence): float
    {
        return self::family($evidence) === self::REPS
            ? (float) ($evidence['reps_needed'] ?? 0) * (float) ($evidence['rep_minutes'] ?? 0)
            : (float) ($evidence['block_minutes'] ?? 0);
    }

    /** @param  array<string, int|float|string>  $evidence */
    private static function family(array $evidence): ?string
    {
        return match (true) {
            isset($evidence['reps_prescribed']) => self::REPS,
            isset($evidence['block_minutes']) => self::BLOCK,
            isset($evidence['ceiling_pace_sec']) => self::STEADY,
            default => null,
        };
    }

    private static function windowWords(string $window): string
    {
        if (preg_match('/^(\d+)(min|s)$/', $window, $match) !== 1) {
            return $window;
        }

        return $match[1].($match[2] === 'min' ? '-minute' : '-second');
    }

    private static function minutes(float $minutes): string
    {
        return (string) (int) round($minutes);
    }

    private static function pace(int $secPerKm): string
    {
        return PaceFormatter::format((float) $secPerKm).'/km';
    }
}
