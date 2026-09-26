<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Enums\StravaGrantReleaseStatus;

final readonly class StravaGrantReleaseResult
{
    public function __construct(
        public StravaGrantReleaseStatus $status,
        public ?string $error = null,
    ) {
    }

    public function freedSlot(): bool
    {
        return in_array($this->status, [StravaGrantReleaseStatus::Released, StravaGrantReleaseStatus::Rejected], true);
    }
}
