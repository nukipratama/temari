<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Whether an easy-effort outing held its heart-rate cap, read from the
 * per-run time over the cap stored on each run's stream summary. Grading and
 * the adaptive plan share it. See
 * `docs/decisions/easy-and-long-runs-are-capped-by-heart-rate.md`.
 */
final readonly class EasyEffort
{
    public const int TOO_HARD_MINUTES = 15;

    public const int EGREGIOUS_MINUTES = 30;

    public const int SHORT_RUN_MINUTES = 75;

    public const float SHORT_RUN_TOO_HARD_SHARE = 0.20;

    public const float SHORT_RUN_EGREGIOUS_SHARE = 0.40;

    private function __construct(
        public int $capBpm,
        public int $overCapSec,
        public int $movingSec,
    ) {
    }

    /**
     * The day's runs that carry a heart-rate reading, summed, or null when
     * none does. Seconds the session asked to be run above the cap are
     * taken off first.
     *
     * @param  array<ActivityDetail>  $runs
     */
    public static function of(array $runs, float $prescribedAboveCapSec = 0.0): ?self
    {
        $cap = null;
        $over = 0;
        $moving = 0;
        foreach ($runs as $run) {
            $summary = StreamSummary::fromArray($run->streamSummary());
            $overSec = $summary->overEasyCapSec();
            if ($overSec === null) {
                continue;
            }
            $cap ??= $summary->easyCapBpm();
            $over += $overSec;
            $moving += (int) ($run->moving_time ?? $run->elapsed_time ?? 0);
        }

        return $cap === null ? null : new self($cap, (int) max(0, round($over - $prescribedAboveCapSec)), $moving);
    }

    public function tooHard(): bool
    {
        return $this->overCapSec > $this->limitSec(self::TOO_HARD_MINUTES, self::SHORT_RUN_TOO_HARD_SHARE);
    }

    public function egregious(): bool
    {
        return $this->overCapSec > $this->limitSec(self::EGREGIOUS_MINUTES, self::SHORT_RUN_EGREGIOUS_SHARE);
    }

    public function overCapMinutes(): float
    {
        return round($this->overCapSec / 60, 1);
    }

    public function limitMinutes(): float
    {
        return round($this->limitSec(self::TOO_HARD_MINUTES, self::SHORT_RUN_TOO_HARD_SHARE) / 60, 1);
    }

    private function limitSec(int $minutes, float $shortRunShare): float
    {
        return $this->movingSec < self::SHORT_RUN_MINUTES * 60
            ? $this->movingSec * $shortRunShare
            : $minutes * 60;
    }
}
