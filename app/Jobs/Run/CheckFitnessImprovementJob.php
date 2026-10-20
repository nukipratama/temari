<?php

declare(strict_types=1);

namespace App\Jobs\Run;

use App\Models\User;
use App\Services\Run\Metrics\FitnessImprovementChecker;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Carbon;

#[UniqueFor(3600)]
final class CheckFitnessImprovementJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(FitnessImprovementChecker $checker): void
    {
        $user = User::query()->notDemo()->find($this->userId);
        if ($user === null) {
            return;
        }

        $checker->check($user, Carbon::today(), Carbon::now());
    }
}
