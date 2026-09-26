<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

it('is unique per user', function (): void {
    $job = new ReconcilePlanJob(42);

    expect($job)
        ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(10)
        ->and($job->maxExceptions)->toBe(3);
});
