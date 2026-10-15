<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's human-readable kind. Distinct from
 * {@see PaceBand}, the VDOT-derived numeric target — a `Long` session
 * typically pairs with the `Easy` pace band unless it's a race-simulation
 * long run at `Marathon` band (see the periodizer's peak-phase handling).
 *
 * `Race` is the goal race itself, the one case whose distance comes from the
 * athlete's {@see \App\Models\RaceGoal} rather than from their training
 * baseline.
 */
enum SessionType: string
{
    case Easy = 'easy';
    case Long = 'long';
    case Tempo = 'tempo';
    case Interval = 'interval';
    case Rest = 'rest';
    case Race = 'race';

    public function isQuality(): bool
    {
        return match ($this) {
            self::Long, self::Tempo, self::Interval => true,
            default => false,
        };
    }

    /**
     * The CR-10 session-RPE range this session usually feels like, or null where
     * no range applies (a race is run all out, a rest day isn't run). See
     * `docs/decisions/a-first-effort-score-re-narrates-the-run.md`.
     *
     * @return array{low: int, high: int}|null
     */
    public function perceivedEffortTarget(): ?array
    {
        return match ($this) {
            self::Easy => ['low' => 2, 'high' => 4],
            self::Long => ['low' => 3, 'high' => 5],
            self::Tempo => ['low' => 6, 'high' => 7],
            self::Interval => ['low' => 8, 'high' => 9],
            self::Race, self::Rest => null,
        };
    }
}
