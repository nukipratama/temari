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
            .'pace/time/hr each carry their own relation (pace.relation/time.relation: faster/slower/'
            ."same; hr.relation: higher/lower/same) -- say that, there is no sign to infer it from: "
            .'seconds_per_km/seconds/bpm are for size only. `direction` (better/worse/flat) is the '
            .'overall call. If past_you is missing, there\'s no good match, and in that case never '
            .'make up a comparison to the past.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return ['past_you' => $this->pastYou->findMatchContext($this->activity, $this->detail)];
    }
}
