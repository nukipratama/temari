<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Story\PastYouMatcher;

final class PastYouTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly PastYouMatcher $pastYou,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_past_you';
    }

    public function description(): string
    {
        return "A similar run of the user's own from the past, to compare against this session. "
            .'`direction` (better/worse/flat) is the call to make -- say that, never infer it from '
            .'the sign of pace_diff_sec/time_diff_sec, which are for size only (seconds). hr_diff_bpm '
            ."positive = HR is higher now. If past_you is missing, there's no good match, and in "
            .'that case never make up a comparison to the past.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return ['past_you' => $this->pastYou->findMatchContext($this->activity, $this->detail)];
    }
}
