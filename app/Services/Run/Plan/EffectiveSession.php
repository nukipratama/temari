<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
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

    private function __construct(
        public SessionType $sessionType,
        public float $coreKm,
        public ?SessionType $easedFromType = null,
        public ?float $easedFromKm = null,
        public ?int $easedPaceSecPerKm = null,
    ) {
    }

    public static function of(PlannedSession $session, float $storedCoreKm): self
    {
        if ($session->rest_clamped_at !== null) {
            return new self(SessionType::Rest, 0.0, $session->session_type, $storedCoreKm);
        }

        if ($session->clamped_km !== null) {
            return new self(SessionType::Easy, $session->clamped_km, $session->session_type, $storedCoreKm);
        }

        if ($session->eased_pace_sec_per_km !== null) {
            return new self($session->session_type, $storedCoreKm, easedPaceSecPerKm: $session->eased_pace_sec_per_km);
        }

        return new self($session->session_type, $storedCoreKm);
    }

    public static function isRecordedOn(PlannedSession $session): bool
    {
        return $session->rest_clamped_at !== null || $session->clamped_km !== null;
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
