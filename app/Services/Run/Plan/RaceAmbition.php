<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\RaceAmbitionState;

final readonly class RaceAmbition
{
    public function __construct(
        public RaceAmbitionState $state,
        public int $targetTimeSec,
        public int $targetPaceSecPerKm,
        public ?int $supportedTimeSec,
        public ?int $supportedPaceSecPerKm,
        public ?float $gapPct,
        public ?string $confidence,
    ) {
    }

    public function prescribedTimeSec(): int
    {
        return match (true) {
            $this->supportedTimeSec === null => $this->targetTimeSec,
            $this->state === RaceAmbitionState::Unsupported => $this->supportedTimeSec,
            $this->state === RaceAmbitionState::LowEvidence => max($this->targetTimeSec, $this->supportedTimeSec),
            default => $this->targetTimeSec,
        };
    }

    /**
     * @return array{state: string, target_time_sec: int, target_pace_sec_per_km: int, supported_time_sec: int|null, supported_pace_sec_per_km: int|null, prescribed_time_sec: int, gap_pct: float|null, evidence_confidence: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'target_time_sec' => $this->targetTimeSec,
            'target_pace_sec_per_km' => $this->targetPaceSecPerKm,
            'supported_time_sec' => $this->supportedTimeSec,
            'supported_pace_sec_per_km' => $this->supportedPaceSecPerKm,
            'prescribed_time_sec' => $this->prescribedTimeSec(),
            'gap_pct' => $this->gapPct,
            'evidence_confidence' => $this->confidence,
        ];
    }
}
