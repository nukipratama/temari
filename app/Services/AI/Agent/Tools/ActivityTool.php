<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentTool;
use Illuminate\Support\Carbon;

/**
 * Base for the reads a run narration can pull, each bound to one activity at
 * construction. None of them take arguments — there is no id for a model to
 * pass, so a tool cannot be pointed at another run or another user.
 */
abstract class ActivityTool implements AgentTool
{
    public function __construct(
        protected readonly Activity $activity,
        protected readonly ActivityDetail $detail,
    ) {
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function summary(): array
    {
        return $this->detail->streamSummary();
    }

    protected function asOf(): Carbon
    {
        return $this->detail->start_date_local ?? Carbon::now();
    }
}
