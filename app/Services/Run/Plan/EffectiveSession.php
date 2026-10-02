<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Enums\PaceBand;
use App\Models\PlannedSession;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * The session a day actually asks for, read off persisted state alone: a
 * recorded rest clamp is a rest day, a recorded eased distance is an easy run
 * of that distance, a recorded pace ease keeps the stored type and distance
 * but runs at the easy band's slow end, and anything else is the stored
 * session. The scorer, the renderer, the week totals and the narrator tools
 * all read this one rule. See `docs/decisions/the-eased-session-leads.md`.
 */
final readonly class EffectiveSession
{
    private const float HELD_TOLERANCE_KM = 0.05;

    /**
     * @param array{hard_minutes: int, original_hard_minutes: int, pace_band: string, pace_sec_per_km: int|null}|null $qualityDose
     * @param array<string, int|float|string>|null $qualityRaceContext
     */
    private function __construct(
        public SessionType $sessionType,
        public float $coreKm,
        public ?SessionType $easedFromType = null,
        public ?float $easedFromKm = null,
        public ?int $easedPaceSecPerKm = null,
        public ?array $qualityDose = null,
        private ?array $qualityRaceContext = null,
    ) {
    }

    public static function of(PlannedSession $session, float $storedCoreKm): self
    {
        if ($session->rest_clamped_at !== null) {
            return new self(SessionType::Rest, 0.0, $session->session_type, $storedCoreKm);
        }

        if ($session->clamped_km !== null) {
            $dose = $session->readiness_assessment['adjustment']['quality_dose'] ?? null;
            if ($dose !== null && $session->session_type->isQuality() && $session->prescribed_hard_minutes !== 0) {
                $dose['hard_minutes'] = min($dose['hard_minutes'], $session->prescribed_hard_minutes ?? $dose['hard_minutes']);
                if ($session->prescribed_hard_minutes !== null && $session->prescribed_pace_band !== null) {
                    $dose['pace_band'] = $session->prescribed_pace_band->value;
                }
                if ($session->prescribed_pace_sec_per_km !== null && $dose['pace_sec_per_km'] !== null) {
                    $dose['pace_sec_per_km'] = max($dose['pace_sec_per_km'], $session->prescribed_pace_sec_per_km);
                }

                return new self(
                    $session->session_type,
                    $session->clamped_km,
                    $session->session_type,
                    $storedCoreKm,
                    qualityDose: $dose,
                    qualityRaceContext: $session->prescribed_hard_minutes === null ? null : $session->prescription_race_context
                );
            }

            return new self(SessionType::Easy, $session->clamped_km, $session->session_type, $storedCoreKm);
        }

        if ($session->eased_pace_sec_per_km !== null) {
            return new self($session->session_type, $storedCoreKm, easedPaceSecPerKm: $session->eased_pace_sec_per_km);
        }

        return new self($session->session_type, $storedCoreKm);
    }

    /**
     * What a settled day was actually asking for: the effective type of the
     * advice the athlete was shown when the grade recorded it, otherwise the
     * type the stored clamp state implies.
     */
    public static function settledTypeOf(PlannedSession $session): SessionType
    {
        $shown = $session->intent_evidence['effective_type'] ?? null;

        return (is_string($shown) ? SessionType::tryFrom($shown) : null) ?? self::of($session, 0.0)->sessionType;
    }

    /**
     * How a credited day counts toward the weekly budget and recovery: the
     * session it was effectively asked to be, with any hard work the runs
     * actually held that the advice did not ask for. An abandoned quality
     * session spends none of its prescribed hard minutes.
     *
     * @return array{session_type: SessionType, prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, hard_minutes?: float|null, demanding?: bool}
     */
    public static function budgetProfileOf(PlannedSession $session): array
    {
        $type = self::settledTypeOf($session);
        $profile = $type === $session->session_type || $type->isQuality()
            ? ['session_type' => $session->session_type, 'prescribed_hard_minutes' => $session->prescribed_hard_minutes ?? 0, 'prescribed_pace_band' => $session->prescribed_pace_band]
            : ['session_type' => $type, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null];

        $evidence = $session->intent_evidence ?? [];
        $addedHardWork = in_array($evidence['stimulus_family'] ?? null, ['tempo', 'interval', 'hard'], true);
        if ($addedHardWork && ! $profile['session_type']->isQuality()) {
            $minutes = $evidence['stimulus_minutes'] ?? null;
            $profile['hard_minutes'] = is_numeric($minutes) ? (float) $minutes : null;
            $profile['demanding'] = true;
        }

        return $profile;
    }

    public static function isRecordedOn(PlannedSession $session): bool
    {
        return $session->rest_clamped_at !== null || $session->clamped_km !== null;
    }

    public function qualityPrescription(): ?IntensityPrescription
    {
        return $this->qualityDose === null ? null : new IntensityPrescription(
            $this->qualityDose['hard_minutes'],
            PaceBand::from($this->qualityDose['pace_band']),
            $this->qualityDose['pace_sec_per_km'],
            null,
            $this->qualityRaceContext,
        );
    }

    /**
     * Whether today's clamp voice is worth fetching: an advisory clamp, or a recorded ease.
     *
     * @param  array{session_type: SessionType, segments: list<SessionSegment>, core_km: float, note: string}|null  $clamp
     */
    public static function clampVoiceNeeded(?array $clamp, ?PlannedSession $todaySession): bool
    {
        return $clamp !== null || ($todaySession !== null && self::isRecordedOn($todaySession));
    }

    public function isEased(): bool
    {
        return $this->easedFromType !== null;
    }

    /** Type and distance stayed, only the pace came down — see {@see \App\Services\Run\Plan\ReadinessClamp::paceEaseApplies()}. */
    public function isPaceEased(): bool
    {
        return $this->easedPaceSecPerKm !== null;
    }

    /** The km the ease took off the day, zero when nothing was eased. */
    public function easedAwayKm(): float
    {
        return $this->isEased() ? $this->easedFromKm - $this->coreKm : 0.0;
    }

    /** The ceiling a recorded ease implies, for the templated note it falls back to. */
    public function impliedCeiling(): ReadinessCeiling
    {
        return match (true) {
            $this->sessionType === SessionType::Rest => ReadinessCeiling::Rest,
            $this->distanceHeld() => ReadinessCeiling::ModerateOk,
            default => ReadinessCeiling::EasyOnly,
        };
    }

    /** Only the intensity came down: the eased run is as long as the session it replaced. */
    public function distanceHeld(): bool
    {
        return $this->easedFromKm !== null
            && $this->sessionType !== SessionType::Rest
            && abs($this->coreKm - $this->easedFromKm) < self::HELD_TOLERANCE_KM;
    }

    /**
     * What a narrator is told the day was eased from: the type, plus the
     * distance only when it moved.
     *
     * @return array{session_type: string, distance_km?: float}|null
     */
    public function easedFromForNarration(): ?array
    {
        if ($this->easedFromType === null || $this->easedFromKm === null) {
            return null;
        }

        if ($this->distanceHeld()) {
            return ['session_type' => $this->easedFromType->value];
        }

        return ['session_type' => $this->easedFromType->value, 'distance_km' => $this->easedFromKm];
    }
}
