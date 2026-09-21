<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecalibrateTrainingHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(PlanRecalibrationService $recalibration): void
    {
        $user = User::query()->notDemo()->find($this->userId);
        if ($user === null) {
            return;
        }

        $recalibration->recalibrate($user);
    }
}
