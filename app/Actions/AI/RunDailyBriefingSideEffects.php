<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Support\Carbon;

final readonly class RunDailyBriefingSideEffects
{
    public function __construct(
        private RestClampRecorder $restClampRecorder,
        private PlanNarrationRequester $planNarration,
    ) {
    }

    public function __invoke(User $user, Carbon $today): void
    {
        $this->restClampRecorder->record($user, $today);
        $this->planNarration->requestClampVoice($user, $today);
    }
}
