<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\RenarrateAfterEffortScore;
use App\Models\Activity;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The one follow-up {@see RenarrateAfterEffortScore} schedules when an effort
 * score lands while the run's speech is still being written, so the speech that
 * then lands without the score is re-narrated. It never schedules another.
 */
class RecheckEffortScoreNarrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $activityId)
    {
    }

    public function handle(RenarrateAfterEffortScore $renarrate): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

        $activity = Activity::query()->with('detail')->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $renarrate($activity, recheck: false);
    }
}
