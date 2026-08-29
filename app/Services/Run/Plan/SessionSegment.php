<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\SegmentKey;

/**
 * One ordered slice of a planned session — e.g. a Tempo day's warmup, its
 * threshold main set, and its cooldown. Always computed fresh by
 * {@see SegmentGenerator}, never persisted (see that class's docblock for
 * why). `minutes` and `paceSecPerKm` are null exactly when the athlete has
 * no VDOT estimate yet — the segment's shape (key, pace target) still renders,
 * just without a concrete duration.
 */
final readonly class SessionSegment
{
    public function __construct(
        public SegmentKey $key,
        public ?float $minutes,
        public string $zone,
        public PaceBand $paceLabel,
        public ?int $paceSecPerKm,
    ) {
    }

    /** @return array{key: string, minutes: float|null, zone: string, pace_label: string, pace_sec_per_km: int|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'minutes' => $this->minutes,
            'zone' => $this->zone,
            'pace_label' => $this->paceLabel->value,
            'pace_sec_per_km' => $this->paceSecPerKm,
        ];
    }
}
