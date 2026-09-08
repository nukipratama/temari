<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the periodizer changed this week from what the phase schedule alone
 * would have produced ({@see \App\Services\Run\Plan\PlanAdapter}). Exactly
 * one reason wins per week, in the priority order the adapter evaluates
 * them: safety signals first, adherence next, then how last week was actually
 * run, race-pace feedback last.
 */
enum AdaptationReason: string
{
    case Steady = 'steady';
    case LowReadiness = 'low_readiness';
    case HighMonotony = 'high_monotony';
    case HighStrain = 'high_strain';
    case MissedWeek = 'missed_week';
    case RanTooHard = 'ran_too_hard';
    case BehindRacePace = 'behind_race_pace';
    case AheadOfRacePace = 'ahead_of_race_pace';

    public function isDeload(): bool
    {
        return match ($this) {
            self::LowReadiness, self::HighMonotony, self::HighStrain, self::MissedWeek => true,
            self::Steady, self::RanTooHard, self::BehindRacePace, self::AheadOfRacePace => false,
        };
    }

    /**
     * Whether a negative `quality_delta` from this reason stops at the week's
     * last quality session instead of emptying the block. Being ahead of the
     * goal time is feedback on a prescription that is working, not a red flag,
     * so it relaxes the work rather than removing it; a week run harder than it
     * was written still gets its quality day taken away. Only ever true with an
     * active race, since the race-gap arm needs one to fire at all.
     */
    public function keepsAQualitySession(): bool
    {
        return $this === self::AheadOfRacePace;
    }

    public function headline(): string
    {
        return match ($this) {
            self::Steady => 'on plan',
            self::LowReadiness, self::HighMonotony, self::HighStrain, self::MissedWeek => 'deload week',
            self::BehindRacePace => 'one more quality session',
            self::RanTooHard => 'one less quality session',
            self::AheadOfRacePace => 'one quality session',
        };
    }

    public function detail(int $adherencePct): string
    {
        return match ($this) {
            self::Steady => "you finished {$adherencePct}% of last week's sessions. nothing to change, the plan stands.",
            self::LowReadiness => 'your readiness sits at rest-only today, so this week drops to deload volume with no quality work.',
            self::HighMonotony => 'every day last week carried the same load. that uniformity is the injury-risk pattern, so this week is a deload.',
            self::HighStrain => 'last week\'s strain ran well past what your fitness supports. this week backs off to deload volume.',
            self::MissedWeek => "you finished {$adherencePct}% of last week's sessions. this week comes back smaller, not doubled.",
            self::RanTooHard => 'you ran last week harder than it was written, so this week carries one less quality session. the easy days need somewhere to be easy.',
            self::BehindRacePace => 'your projected finish is behind your goal time. one extra quality session a week from here.',
            self::AheadOfRacePace => 'your projected finish is already inside your goal time. the week holds a single quality session, eased toward goal pace rather than dropped, and banks the rest as freshness.',
        };
    }
}
