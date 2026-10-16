<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu;

final readonly class IntervalsIcuWorkout
{
    public function __construct(
        public string $name,
        public string $description,
    ) {
    }
}
