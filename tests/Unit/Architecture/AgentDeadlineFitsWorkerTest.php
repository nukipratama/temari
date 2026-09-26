<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;

/**
 * A narrator's deadline can overshoot by one Azure request. Activity groups
 * share the worker's safe window across two narrator calls, so the second must
 * fit before the worker's hard timeout.
 */
it('fits one narrator inside the worker and bounds the multi-call activity group', function (): void {
    $deadline = (int) config('ai.agent.deadline_seconds');
    $requestTimeout = (int) config('azure_openai.timeout');
    $workerTimeout = (int) config('horizon.defaults.supervisor-ai.timeout');
    $safetyMargin = AnalyzeActivityJob::WORKER_TIMEOUT_SAFETY_MARGIN_SECONDS;
    $singleCallWorstCase = $deadline + $requestTimeout;

    expect($deadline)->toBeGreaterThan(0)
        ->and($requestTimeout)->toBeGreaterThan(0)
        ->and($singleCallWorstCase + $safetyMargin)->toBeLessThan(
            $workerTimeout,
            "ai.agent.deadline_seconds ({$deadline}) + azure_openai.timeout ({$requestTimeout}) + the activity safety margin ({$safetyMargin}) must stay under supervisor-ai timeout ({$workerTimeout}).",
        )
        ->and(2 * $singleCallWorstCase)->toBeGreaterThan(
            $workerTimeout - $safetyMargin,
            'The activity group must keep its second narrator conditional on the remaining job deadline.',
        );
})->group('structure');

/**
 * Laravel re-queues a reserved job after `retry_after` seconds. Below the
 * worker timeout, a narration run still in flight is handed to a second worker
 * and the whole run is billed twice.
 */
it('keeps the queue retry window above every worker timeout', function (): void {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    /** @var array<string, array{timeout?: int}> $supervisors */
    $supervisors = config('horizon.defaults');

    foreach ($supervisors as $name => $supervisor) {
        expect($retryAfter)->toBeGreaterThan(
            $supervisor['timeout'] ?? 0,
            "queue.connections.redis.retry_after ({$retryAfter}) must exceed the {$name} timeout.",
        );
    }
})->group('structure');
