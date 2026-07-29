<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

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

    protected function summary(): StreamSummary
    {
        return StreamSummary::fromArray($this->detail->streamSummary());
    }
}
