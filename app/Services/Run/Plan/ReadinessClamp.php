<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Enums\PaceBand;
use App\Services\Run\Metrics\PaceFormatter;
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
     * @param  list<string>  $reasons
     * @return array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string, quality_dose?: array{hard_minutes: int, original_hard_minutes: int, pace_band: string, pace_sec_per_km: int|null}}|null
     *                                                                                                            null when the stored session already fits under the ceiling
     */
    public static function apply(
        SessionType $sessionType,
        PlanPhase $phase,
        ?float $raceDistanceM,
        float $longRunBaselineKm,
        float $volumeMultiplier,
        float $longRunCapKm,
        ?array $paces,
        ReadinessCeiling $ceiling,
        float $longRunProgressionCapKm = INF,
        array $reasons = [],
        ?IntensityPrescription $prescription = null,
    ): ?array {
        $requiredRank = self::requiredRank($sessionType);
        if ($requiredRank <= $ceiling->rank()) {
            return null;
        }

        if ($ceiling === ReadinessCeiling::ModerateOk && $sessionType === SessionType::Race) {
            $km = ($raceDistanceM ?? 0) / 1000;

            return ['session_type' => $sessionType, 'segments' => SegmentGenerator::easyBlock($km, $paces), 'core_km' => $km,
                'note' => self::noteFor($sessionType, $ceiling, $reasons) ?? 'keep the event distance and use a conservative effort.'];
        }

        if ($ceiling === ReadinessCeiling::ModerateOk && in_array($sessionType, [SessionType::Tempo, SessionType::Interval], true) && $prescription !== null && $prescription->paceBand !== null && ! $prescription->isEasy()) {
            if (TimeTrial::isTrial($prescription->raceContext)) {
                $km = SegmentGenerator::coreKmFor($sessionType, false, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm, raceContext: $prescription->raceContext);

                return ['session_type' => SessionType::Easy, 'segments' => SegmentGenerator::easyBlock($km, $paces), 'core_km' => $km,
                    'note' => self::noteFor($sessionType, $ceiling, $reasons) ?? self::moderateOkNote()];
            }
            $minutes = max(1, (int) floor($prescription->hardMinutes * 0.75));
            $reduced = new IntensityPrescription($minutes, $prescription->paceBand, $prescription->paceSecPerKm, $prescription->reason, $prescription->raceContext);
            $km = SegmentGenerator::coreKmFor($sessionType, false, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm);
            $segments = SegmentGenerator::forPrescription($sessionType, $phase, $km, $paces, $reduced);
            $minutes = (int) array_sum(array_map(static fn (SessionSegment $segment): float => $segment->paceLabel === PaceBand::Easy ? 0.0 : ($segment->minutes ?? 0.0), $segments));
            if ($minutes === 0 || $minutes >= $prescription->hardMinutes) {
                return ['session_type' => SessionType::Easy, 'segments' => SegmentGenerator::easyBlock($km, $paces), 'core_km' => $km,
                    'note' => 'a smaller useful quality dose does not fit this session, so keep this one easy.'];
            }

            return ['session_type' => $sessionType, 'segments' => $segments, 'core_km' => $km,
                'note' => self::qualityDoseNote($sessionType, $reasons, $minutes, $prescription->hardMinutes),
                'quality_dose' => ['hard_minutes' => $minutes, 'original_hard_minutes' => $prescription->hardMinutes, 'pace_band' => $prescription->paceBand->value, 'pace_sec_per_km' => $prescription->paceSecPerKm]];
        }

        $easyOnlyKm = SegmentGenerator::coreKmFor(SessionType::Easy, $sessionType === SessionType::Long, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm);
        if ($sessionType === SessionType::Long) {
            $easyOnlyKm = min($easyOnlyKm, SegmentGenerator::coreKmFor(
                SessionType::Long,
                false,
                $longRunBaselineKm,
                $volumeMultiplier,
                $longRunCapKm,
                longRunProgressionCapKm: $longRunProgressionCapKm,
            ));
        }

        return match ($ceiling) {
            ReadinessCeiling::Rest => [
                'session_type' => SessionType::Rest,
                'segments' => [],
                'core_km' => 0.0,
                'note' => self::noteFor($sessionType, $ceiling, $reasons) ?? self::restNote($sessionType),
            ],
            // Long requires ModerateOk, so an EasyOnly ceiling — stricter than
            // ModerateOk — does send a Long day through this arm too.
            ReadinessCeiling::EasyOnly => [
                'session_type' => SessionType::Easy,
                'segments' => SegmentGenerator::forCoreKm(
                    SessionType::Easy,
                    $phase,
                    $raceDistanceM,
                    $easyOnlyKm,
                    $paces,
                ),
                'core_km' => $easyOnlyKm,
                'note' => self::noteFor($sessionType, $ceiling, $reasons) ?? self::easyOnlyNote($sessionType),
            ],
            // Only reachable for Tempo/Interval (their requiredRank alone
            // exceeds ModerateOk) — keeps the day's own size, just re-paced
            // to Easy, since a Long day never needs more than ModerateOk.
            ReadinessCeiling::ModerateOk => [
                'session_type' => SessionType::Easy,
                'segments' => SegmentGenerator::easyEquivalentOf($sessionType, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm, $paces),
                'core_km' => SegmentGenerator::coreKmFor($sessionType, false, $longRunBaselineKm, $volumeMultiplier, $longRunCapKm),
                'note' => self::noteFor($sessionType, $ceiling, $reasons) ?? self::moderateOkNote(),
            ],
            ReadinessCeiling::QualityOk => null, // unreachable: nothing requires more than QualityOk
        };
    }

    /**
     * Whether today's ceiling would downgrade this session all the way to a
     * full rest — the one clamp outcome compliance has to know about, since
     * an athlete who takes the rest the card prescribed would otherwise be
     * graded against the session it replaced and score `missed` for
     * complying. Shares {@see self::requiredRank()} with {@see self::apply()}
     * so the two can never disagree about what the ceiling permits, and needs
     * neither paces nor a volume multiplier: the `Rest` arm of `apply()` uses
     * neither. See `docs/decisions/readiness-clamp-is-advisory.md`.
     */
    public static function clampsToRest(SessionType $sessionType, ReadinessCeiling $ceiling): bool
    {
        return $ceiling === ReadinessCeiling::Rest
            && self::requiredRank($sessionType) > $ceiling->rank();
    }

    /**
     * What today's ceiling would downgrade this session to, or null when the
     * session already fits under it. The general form of
     * {@see self::clampsToRest()}, and it exists for the same reason: a caller
     * that needs only the OUTCOME should not have to build a segment list to
     * learn it. {@see \App\Services\Run\Plan\ClampNarrationContext} narrates the
     * downgrade and has neither paces nor a volume multiplier to hand.
     *
     * Shares {@see self::requiredRank()} with {@see self::apply()}, so the two
     * can never disagree about what the ceiling permits.
     */
    public static function downgradeFor(SessionType $sessionType, ReadinessCeiling $ceiling): ?SessionType
    {
        if (self::requiredRank($sessionType) <= $ceiling->rank()) {
            return null;
        }

        return match ($ceiling) {
            ReadinessCeiling::Rest => SessionType::Rest,
            ReadinessCeiling::EasyOnly, ReadinessCeiling::ModerateOk => SessionType::Easy,
            ReadinessCeiling::QualityOk => null,
        };
    }

    /**
     * The one pace-only lever: a session {@see self::apply()} leaves
     * completely alone because it already clears the ceiling, but only
     * just — an Easy day at an EasyOnly ceiling, or a Long day at
     * ModerateOk (marathon-pace long runs included: type and distance stay,
     * only the pace comes down). Never true on a day `apply()` already
     * downgraded, since a session that needed MORE than the ceiling allows
     * fails this exact-match check.
     */
    public static function paceEaseApplies(SessionType $sessionType, ReadinessCeiling $ceiling): bool
    {
        return ($sessionType === SessionType::Easy && $ceiling === ReadinessCeiling::EasyOnly)
            || ($sessionType === SessionType::Long && $ceiling === ReadinessCeiling::ModerateOk);
    }

    /**
     * The line a pace-only ease always carries — rule-based only, never
     * narrated: unlike every other clamp outcome, this one requests no
     * `plan_clamp_voice` and sends no notification. See {@see self::paceEaseApplies()}.
     */
    /** @param list<string> $reasons */
    public static function paceEaseNote(array $reasons = []): string
    {
        foreach ([
            'training_form_fatigued' => 'your current training form is showing fatigue, so keep this one at the slower end of easy.',
            'demanding_session_within_24h' => 'you completed a demanding session within the last day, so keep this one at the slower end of easy.',
            'closely_spaced_demanding_sessions' => 'hard sessions have landed close together, so keep this one at the slower end of easy.',
            'weekly_load_above_personal_range' => 'your recent measured training load is above your usual range, so keep this one at the slower end of easy.',
            'high_training_monotony' => 'your recent training load has been unusually uniform, so keep this one at the slower end of easy.',
            'volume_increased_sharply' => "this week's running volume is well above last week's, so keep this one at the slower end of easy.",
            'running_ahead_of_plan' => "you've run well past this week's plan so far, so keep this one at the slower end of easy.",
        ] as $reason => $note) {
            if (in_array($reason, $reasons, true)) {
                return $note;
            }
        }

        return 'keep this one at the slower end of easy.';
    }

    /**
     * The ceiling rank a session needs to run as prescribed. Quality work
     * (Tempo/Interval, in Daniels' vocabulary) needs the optimistic default;
     * a Long day is a volume day, not an intensity one, so it only needs
     * "moderate" clearance; Easy needs the floor above Rest.
     *
     * A race has no readiness privilege. It stays as prescribed when evidence
     * supports quality, and real readiness concerns can still advise easing it.
     */
    private static function requiredRank(SessionType $sessionType): int
    {
        return match ($sessionType) {
            SessionType::Rest => ReadinessCeiling::Rest->rank(),
            SessionType::Easy => ReadinessCeiling::EasyOnly->rank(),
            SessionType::Long => ReadinessCeiling::ModerateOk->rank(),
            SessionType::Tempo, SessionType::Interval, SessionType::Race => ReadinessCeiling::QualityOk->rank(),
        };
    }

    /**
     * The line a step-down is explained by, or null when the session already
     * fits under the ceiling. Split out of {@see self::apply()} for the same
     * reason as {@see self::downgradeFor()}: a caller that needs only the
     * explanation should not have to build a segment list to reach it.
     */
    /** @param list<string> $reasons */
    public static function noteFor(SessionType $sessionType, ReadinessCeiling $ceiling, array $reasons = []): ?string
    {
        if (self::requiredRank($sessionType) <= $ceiling->rank()) {
            return null;
        }

        $specificNote = self::specificNote($reasons);
        if ($ceiling === ReadinessCeiling::ModerateOk && $sessionType === SessionType::Race) {
            return ($specificNote === null ? '' : $specificNote.' ').'keep the event distance and use a conservative effort.';
        }
        if ($specificNote !== null) {
            return $specificNote;
        }

        return match ($ceiling) {
            ReadinessCeiling::Rest => self::restNote($sessionType),
            ReadinessCeiling::EasyOnly => self::easyOnlyNote($sessionType),
            ReadinessCeiling::ModerateOk => self::moderateOkNote(),
            ReadinessCeiling::QualityOk => null,
        };
    }

    /** @param list<string> $reasons */
    private static function specificNote(array $reasons): ?string
    {
        foreach ([
            'training_form_overreaching' => "your current training form is showing overreaching, so today's a full rest instead.",
            'already_ran_today' => "you already ran today, so this one stays easy instead of the planned session.",
            'demanding_session_within_24h' => "you completed a demanding session within the last day, so quality can wait.",
            'closely_spaced_demanding_sessions' => 'hard sessions have landed close together, so quality can wait.',
            'weekly_load_above_personal_range' => 'your recent measured training load is above your usual range, so quality can wait.',
            'training_form_fatigued' => "your current training form is showing fatigue, so quality can wait.",
            'high_training_monotony' => 'your recent training load has been unusually uniform, so quality can wait.',
            'volume_increased_sharply' => "this week's running volume is well above last week's, so quality can wait.",
            'running_ahead_of_plan' => "you've run well past this week's plan so far, so quality can wait.",
        ] as $reason => $note) {
            if (in_array($reason, $reasons, true)) {
                return $note;
            }
        }

        return null;
    }

    private static function moderateOkNote(): string
    {
        return "quality work can wait, today's the easy version instead.";
    }

    /** @param list<string> $reasons */
    public static function qualityDoseNote(SessionType $sessionType, array $reasons, int $minutes, int $originalMinutes, ?int $paceSecPerKm = null): string
    {
        $cause = self::specificNote($reasons);
        if ($minutes === $originalMinutes && $paceSecPerKm !== null) {
            return ($cause === null ? '' : $cause.' ')."keep all {$minutes} hard minutes of the {$sessionType->value} work, a touch easier at ".PaceFormatter::format((float) $paceSecPerKm).'/km.';
        }
        $cause = $cause === null ? '' : str_replace('quality can wait.', 'reduce the quality dose.', $cause).' ';

        return $cause."keep the {$sessionType->value} intent with {$minutes} hard minutes instead of {$originalMinutes}.";
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
