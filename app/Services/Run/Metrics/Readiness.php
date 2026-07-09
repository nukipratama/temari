<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Turns the runner's load + recovery signals into a deterministic, unit-testable
 * safety decision: how hard a session to encourage today ({@see ReadinessCeiling})
 * and whether to gently nudge them to build rather than coast into detraining.
 *
 * Do-no-harm design (the safety mandate for this signal):
 * - Asymmetric caution. Backing off needs ONE red flag; reaching quality needs
 *   every green signal to line up. Each guardrail can only lower the ceiling.
 * - A progression/build signal never overrides a red flag: {@see $buildNudge}
 *   is only allowed once the ceiling already permits moderate-or-harder work.
 */
final readonly class Readiness
{
    public function __construct(
        public ReadinessCeiling $ceiling,
        public bool $buildNudge,
    ) {
    }

    /**
     * @param  string|null  $formStatus  fresh|optimal|fatigued|overreaching (null = unknown)
     * @param  int|null  $recoveryHours  recovery available for the next session
     * @param  bool  $ranToday  already trained today (own session in the bag)
     * @param  float|null  $monotony  Foster monotony (>2 = injury-risk uniformity)
     * @param  float|null  $volumeRampPct  week-over-week volume change, % (null = no baseline)
     * @param  string  $fitnessTrend  naik|plateau|turun (CTL slope)
     */
    public static function assess(
        ?string $formStatus,
        ?int $recoveryHours,
        bool $ranToday,
        ?float $monotony,
        ?float $volumeRampPct,
        string $fitnessTrend,
    ): self {
        // Start optimistic; every guardrail can only cap it down.
        $ceiling = ReadinessCeiling::QualityOk;

        // --- Hard red flags: any ONE backs the runner off. ---
        if ($formStatus === 'overreaching') {
            $ceiling = $ceiling->capTo(ReadinessCeiling::Rest);
        }
        if ($ranToday) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
        }
        if ($formStatus === 'fatigued') {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
        }
        if ($monotony !== null && $monotony > 2.0) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
        }
        if ($recoveryHours !== null && $recoveryHours < 24) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
        }

        // --- Softer caps: allow moderate, withhold quality. ---
        if ($monotony !== null && $monotony >= 1.8) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
        }
        if ($recoveryHours !== null && $recoveryHours < 48) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
        }
        if ($volumeRampPct !== null && $volumeRampPct > 15.0) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
        }

        // Quality needs positive fitness confirmation. Unknown/negative form or
        // unknown recovery can't earn a quality day.
        if ($formStatus !== 'fresh' && $formStatus !== 'optimal') {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
        }
        if ($recoveryHours === null) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
        }

        // --- Anti-detraining nudge: only when nothing above capped us to
        // easy/rest, so a build signal never contradicts a red flag. ---
        $buildNudge = $formStatus === 'fresh'
            && $fitnessTrend !== 'naik'
            && ! $ranToday
            && $ceiling->rank() >= ReadinessCeiling::ModerateOk->rank();

        return new self($ceiling, $buildNudge);
    }
}
