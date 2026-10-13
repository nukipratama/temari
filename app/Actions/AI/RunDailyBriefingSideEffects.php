<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use App\Services\AI\ClampVoiceRequester;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Support\Carbon;

final readonly class RunDailyBriefingSideEffects
{
    public function __construct(
        private RestClampRecorder $restClampRecorder,
        private ClampVoiceRequester $clampVoice,
    ) {
    }

    public function __invoke(User $user, Carbon $today): void
    {
        $this->restClampRecorder->record($user, $today);
        $this->clampVoice->request($user, $today);
    }
}
