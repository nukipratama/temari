<?php

declare(strict_types=1);

/**
 * A narration run is bounded by three numbers that live in three config files,
 * and only their relation is load-bearing: the run's own wall-clock deadline,
 * the per-request Azure timeout it can overshoot that deadline by, and the
 * worker timeout that kills the job outright. Sized wrong, the worker wins —
 * Azure bills the step, the job dies mid-request, and the athlete gets an empty
 * block. Editing any one of the three in isolation is what reopens that gap.
 */
it('gives a run its full deadline plus one in-flight request before the worker timeout', function (): void {
    $deadline = (int) config('ai.agent.deadline_seconds');
    $requestTimeout = (int) config('azure_openai.timeout');
    $workerTimeout = (int) config('horizon.defaults.supervisor-ai.timeout');

    expect($deadline)->toBeGreaterThan(0)
        ->and($requestTimeout)->toBeGreaterThan(0)
        ->and($deadline + $requestTimeout)->toBeLessThan(
            $workerTimeout,
            "ai.agent.deadline_seconds ({$deadline}) + azure_openai.timeout ({$requestTimeout}) must stay under the supervisor-ai timeout ({$workerTimeout}).",
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
