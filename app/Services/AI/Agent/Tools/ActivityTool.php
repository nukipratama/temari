<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;

/**
 * Base for the reads about one run, each bound to that run at construction.
 */
abstract class ActivityTool extends NoArgumentTool
{
    public function __construct(
        protected readonly Activity $activity,
        protected readonly ActivityDetail $detail,
    ) {
    }

    /** @return array<string, mixed> */
    protected function summary(): array
    {
        return $this->detail->streamSummary();
    }
}
