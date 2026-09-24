<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Models\PlannedSession;

final readonly class IntensityPrescription
{
    /** @param array<string, int|float|string>|null $raceContext */
    public function __construct(
        public int $hardMinutes,
        public ?PaceBand $paceBand,
        public ?int $paceSecPerKm,
        public ?string $reason,
        public ?array $raceContext = null,
    ) {
    }

    /** @return array{prescribed_hard_minutes: int, prescribed_pace_band: PaceBand|null, prescribed_pace_sec_per_km: int|null, prescription_reason: string|null, prescription_race_context: array<string, int|float|string>|null} */
    public function toArray(): array
    {
        return [
            'prescribed_hard_minutes' => $this->hardMinutes,
            'prescribed_pace_band' => $this->paceBand,
            'prescribed_pace_sec_per_km' => $this->paceSecPerKm,
            'prescription_reason' => $this->reason,
            'prescription_race_context' => $this->raceContext,
        ];
    }

    public function isEasy(): bool
    {
        // A missing VDOT pace does not erase the hard-day decision. It only
        // means the display cannot attach a seconds-per-kilometre target yet.
        return $this->hardMinutes === 0 || $this->paceBand === null;
    }

    public static function fromSession(PlannedSession $session): ?self
    {
        if ($session->prescribed_hard_minutes === null) {
            return null;
        }

        return new self(
            $session->prescribed_hard_minutes,
            $session->prescribed_pace_band,
            $session->prescribed_pace_sec_per_km,
            $session->prescription_reason,
            $session->prescription_race_context,
        );
    }
}
