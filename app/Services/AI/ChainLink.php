<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class ChainLink
{
    public function __construct(
        public int $subjectId,
        public ?string $discriminator = null,
    ) {
    }
}
