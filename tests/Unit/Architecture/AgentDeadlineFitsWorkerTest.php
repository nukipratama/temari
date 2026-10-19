<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use Symfony\Component\Yaml\Yaml;

/**
 * A narrator's deadline can overshoot by one Azure request. Activity groups
 * share the worker's safe window, so each call receives only the deadline that
 * remains after reserving its request timeout.
 */
it('fits one narrator and the minimum speech pass inside the worker', function (): void {
    $deadline = config()->integer('ai.agent.deadline_seconds');
    $requestTimeout = config()->integer('azure_openai.timeout');
    $workerTimeout = config()->integer('horizon.defaults.supervisor-ai.timeout');
    $safetyMargin = AnalyzeActivityJob::WORKER_TIMEOUT_SAFETY_MARGIN_SECONDS;
    $singleCallWorstCase = $deadline + $requestTimeout;
    $minimumSpeechCall = AnalyzeActivityJob::MINIMUM_SPEECH_DEADLINE_SECONDS + $requestTimeout;

    expect($deadline)->toBeGreaterThan(0)
        ->and($requestTimeout)->toBeGreaterThan(0)
        ->and($singleCallWorstCase + $safetyMargin)->toBeLessThan(
            $workerTimeout,
            "ai.agent.deadline_seconds ({$deadline}) + azure_openai.timeout ({$requestTimeout}) + the activity safety margin ({$safetyMargin}) must stay under supervisor-ai timeout ({$workerTimeout}).",
        )
        ->and($minimumSpeechCall + $safetyMargin)->toBeLessThan(
            $workerTimeout,
            'The minimum speech deadline plus its request timeout and safety margin must fit inside supervisor-ai timeout.',
        );
})->group('structure');

/**
 * Laravel re-queues a reserved job after `retry_after` seconds. Below the
 * worker timeout, a narration run still in flight is handed to a second worker
 * and the whole run is billed twice.
 */
it('keeps the queue retry window above every worker timeout', function (): void {
    $retryAfter = config()->integer('queue.connections.redis.retry_after');

    /** @var array<string, array{timeout?: int}> $supervisors */
    $supervisors = config('horizon.defaults');

    foreach ($supervisors as $name => $supervisor) {
        expect($retryAfter)->toBeGreaterThan(
            $supervisor['timeout'] ?? 0,
            "queue.connections.redis.retry_after ({$retryAfter}) must exceed the {$name} timeout.",
        );
    }
})->group('structure');

it('gives Horizon a stop grace above the ai worker timeout', function (): void {
    $configured = data_get(Yaml::parseFile(base_path('compose.prod.yaml')), 'services.horizon.stop_grace_period');
    $grace = is_string($configured) ? $configured : throw new InvalidArgumentException('horizon stop_grace_period is not a string.');
    $graceSeconds = (int) rtrim($grace, 's');
    $workerTimeout = config()->integer('horizon.defaults.supervisor-ai.timeout');
    $singleCallWorstCase = config()->integer('ai.agent.deadline_seconds') + config()->integer('azure_openai.timeout');

    expect($grace)->toMatch('/^\d+s$/')
        ->and($graceSeconds)->toBeGreaterThan($workerTimeout, "horizon stop_grace_period ({$grace}) must exceed the supervisor-ai timeout ({$workerTimeout}).")
        ->and($workerTimeout)->toBeGreaterThan($singleCallWorstCase);
})->group('structure');
