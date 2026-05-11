<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

/**
 * Declarative rule grid mapping multi-signal runner state → vibe label.
 *
 * Reads like a table on purpose: anyone tweaking thresholds should be able
 * to see all the rules in one place without tracing logic across services.
 *
 * Inputs (all derived from training-load + recent activity context):
 *   form           : signed float (CTL - ATL)
 *   form_status    : 'fresh' | 'optimal' | 'fatigued' | 'overreaching'
 *   days_since_run : days since most recent activity (null = first ever)
 *   recent_pr      : bool — did the user break any PR in the last 14 days
 *   decoupling_avg : avg cardiac decoupling over last 4 weeks (raw %, nullable)
 *
 * Output vibe enum keys (the display labels live in `Vibe::LABELS`):
 *   hibernating / fresh / bouncy / steady / worn_down / cooked / stretched_thin / pumped
 *
 * Order matters — first matching rule wins, so the most-specific conditions
 * come first.
 */
class VibeMatrix
{
    /**
     * @param  array{form: float, form_status: string, days_since_run: ?int, recent_pr: bool, decoupling_avg: ?float}  $signals
     */
    public function pick(array $signals): string
    {
        $status = $signals['form_status'];
        $daysSince = $signals['days_since_run'];
        $hasRecentPr = $signals['recent_pr'];
        $decoupling = $signals['decoupling_avg'];

        // No runs in 10+ days (or never run) — couch mode regardless of form.
        if ($daysSince === null || $daysSince >= 10) {
            return 'hibernating';
        }

        // Recent PR + form not in the red = celebrate. A fatigued or
        // overreaching state still falls through to its own warning vibe,
        // even when a PR was set in the trailing window.
        if ($hasRecentPr && ! in_array($status, ['fatigued', 'overreaching'], strict: true)) {
            return 'pumped';
        }

        if ($status === 'fresh') {
            return 'fresh';
        }

        if ($status === 'overreaching') {
            // High decoupling avg on top of overreaching = aerobic ceiling concern,
            // not just fatigue. "Stretched thin" reads as "building too fast".
            if ($decoupling !== null && $decoupling > 5.0) {
                return 'stretched_thin';
            }

            return 'cooked';
        }

        if ($status === 'fatigued') {
            return 'worn_down';
        }

        // status === 'optimal' beyond here.
        if ($decoupling !== null && $decoupling < 0) {
            // Decoupling negative = HR/pace held steady or improved → aerobic system humming.
            return 'bouncy';
        }

        return 'steady';
    }
}
