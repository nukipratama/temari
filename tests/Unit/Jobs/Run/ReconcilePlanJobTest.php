<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;

it('is unique per user and does not overlap its own reconciliation', function (): void {
    $job = new ReconcilePlanJob(42);

    expect($job)
        ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(10)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});
