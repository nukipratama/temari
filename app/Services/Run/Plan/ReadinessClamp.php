<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * Render-time, deterministic downgrade of a single stored session against
 * the CURRENT {@see ReadinessCeiling} — never a narrator, never persisted
 * (see the "Readiness clamp" section of `docs/features/plan-periodizer.md`).
 * Compares what the stored session implies against what the ceiling allows
 * today; when the stored session asks for more than the ceiling permits,
 * returns a downgraded segment list plus a short templated explanation.
 *
 * Scope: only ever called against TODAY's row. A future day's readiness is
 * unknowable today, and clamping a whole training block by this moment's
 * fatigue would defeat periodization — see the controller for where this is
 * invoked.
 */
final class ReadinessClamp
{
    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
     * @return array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null
     *                                                                                                            null when the stored session already fits under the ceiling
     */
    public static function apply(
        SessionType $sessionType,
        PlanPhase $phase,
        bool $isMarathonDistance,
        float $longRunBaselineKm,
        float $volumeMultiplier,
        ?array $paces,
        ReadinessCeiling $ceiling,
    ): ?array {
        $requiredRank = self::requiredRank($sessionType);
        if ($requiredRank <= $ceiling->rank()) {
            return null;
        }

        return match ($ceiling) {
            ReadinessCeiling::Rest => [
                'session_type' => SessionType::Rest,
                'segments' => [],
                'core_km' => 0.0,
                'note' => self::restNote($sessionType),
            ],
            // Long is the one session type ModerateOk already clears (its own
            // requiredRank), so this arm only ever downgrades Tempo/Interval —
            // Long never reaches here.
            ReadinessCeiling::EasyOnly => [
                'session_type' => SessionType::Easy,
                'segments' => SegmentGenerator::generate(
                    SessionType::Easy,
                    $phase,
                    $isMarathonDistance,
                    $sessionType === SessionType::Long,
                    $longRunBaselineKm,
                    $volumeMultiplier,
                    $paces,
                ),
                'core_km' => SegmentGenerator::coreKmFor(SessionType::Easy, $sessionType === SessionType::Long, $longRunBaselineKm, $volumeMultiplier),
                'note' => self::easyOnlyNote($sessionType),
            ],
            // Only reachable for Tempo/Interval (their requiredRank alone
            // exceeds ModerateOk) — keeps the day's own size, just re-paced
            // to Easy, since a Long day never needs more than ModerateOk.
            ReadinessCeiling::ModerateOk => [
                'session_type' => SessionType::Easy,
                'segments' => SegmentGenerator::easyEquivalentOf($sessionType, $longRunBaselineKm, $volumeMultiplier, $paces),
                'core_km' => SegmentGenerator::coreKmFor($sessionType, false, $longRunBaselineKm, $volumeMultiplier),
                'note' => "Your form dipped, so today's the easy version instead.",
            ],
            ReadinessCeiling::QualityOk => null, // unreachable: nothing requires more than QualityOk
        };
    }

    /**
     * The ceiling rank a session needs to run as prescribed. Quality work
     * (Tempo/Interval, in Daniels' vocabulary) needs the optimistic default;
     * a Long day is a volume day, not an intensity one, so it only needs
     * "moderate" clearance; Easy needs the floor above Rest.
     */
    private static function requiredRank(SessionType $sessionType): int
    {
        return match ($sessionType) {
            SessionType::Rest => ReadinessCeiling::Rest->rank(),
            SessionType::Easy => ReadinessCeiling::EasyOnly->rank(),
            SessionType::Long => ReadinessCeiling::ModerateOk->rank(),
            SessionType::Tempo, SessionType::Interval => ReadinessCeiling::QualityOk->rank(),
        };
    }

    private static function restNote(SessionType $original): string
    {
        return match ($original) {
            SessionType::Long => "You're carrying a lot right now, today's a full rest instead of the long run.",
            SessionType::Tempo, SessionType::Interval => "Recovery's still catching up, quality work waits, today's a full rest.",
            default => "Recovery's still catching up, today's a full rest instead.",
        };
    }

    private static function easyOnlyNote(SessionType $original): string
    {
        return match ($original) {
            SessionType::Long => "Long runs ask a lot of a tired body, today scales back to a shorter easy one.",
            default => "Quality work waits until you're fresher, today's the easy version instead.",
        };
    }
}
