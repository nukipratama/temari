<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\SegmentKey;

/**
 * One ordered slice of a planned session — e.g. a Tempo day's warmup and
 * its threshold main set. Always computed fresh by
 * {@see SegmentGenerator}, never persisted (see that class's docblock for
 * why). `minutes`, `km` and `paceSecPerKm` are null exactly when the athlete
 * has no VDOT estimate yet — the segment's shape (key, pace target) still
 * renders, just without a concrete duration. A main block knows its own
 * distance either way, so only its bookends go null.
 */
final readonly class SessionSegment
{
    public function __construct(
        public SegmentKey $key,
        public ?float $minutes,
        public string $zone,
        public PaceBand $paceLabel,
        public ?int $paceSecPerKm,
        public ?float $km = null,
    ) {
    }

    /** @return array{key: string, minutes: float|null, km: float|null, zone: string, pace_label: string, pace_sec_per_km: int|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'minutes' => $this->minutes,
            'km' => $this->km,
            'zone' => $this->zone,
            'pace_label' => $this->paceLabel->value,
            'pace_sec_per_km' => $this->paceSecPerKm,
        ];
    }
}
