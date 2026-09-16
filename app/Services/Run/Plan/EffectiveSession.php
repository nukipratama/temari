<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Models\PlannedSession;

/**
 * The session a day actually asks for, read off persisted state alone: a
 * recorded rest clamp is a rest day, a recorded eased distance is an easy run
 * of that distance, and anything else is the stored session. The scorer, the
 * renderer, the week totals and the narrator tools all read this one rule.
 * See `docs/decisions/the-eased-session-leads.md`.
 */
final readonly class EffectiveSession
{
    private const float HELD_TOLERANCE_KM = 0.05;

    private function __construct(
        public SessionType $sessionType,
        public float $coreKm,
        public ?SessionType $easedFromType = null,
        public ?float $easedFromKm = null,
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

        return new self($session->session_type, $storedCoreKm);
    }

    public static function isRecordedOn(PlannedSession $session): bool
    {
        return $session->rest_clamped_at !== null || $session->clamped_km !== null;
    }

    public function isEased(): bool
    {
        return $this->easedFromType !== null;
    }

    /** The km the ease took off the day, zero when nothing was eased or only the intensity was. */
    public function easedAwayKm(): float
    {
        return ($this->easedFromKm ?? $this->coreKm) - $this->coreKm;
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
