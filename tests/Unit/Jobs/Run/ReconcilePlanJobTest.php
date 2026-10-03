<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Support\Facades\Queue;

it('is unique per user', function (): void {
    $job = new ReconcilePlanJob(42);

    expect($job)
        ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42')
        ->and(Queue::connection('sync')->getJobTries($job))->toBe(10)
        ->and(new ReflectionClass($job)->getAttributes(MaxExceptions::class)[0]->newInstance()->maxExceptions)->toBe(3);
});
