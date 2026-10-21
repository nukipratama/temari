<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const OUTSIDER_TRIGGERS = [
    'pull_request', 'pull_request_target', 'pull_request_review', 'pull_request_review_comment',
    'issue_comment', 'issues', 'discussion', 'discussion_comment', 'fork', 'watch',
];

/** @return array<mixed> */
function workflowFile(string $path): array
{
    return arrayOf(Yaml::parseFile(base_path($path)));
}

/** @param array<mixed> $workflow */
function outsiderCanTrigger(array $workflow): bool
{
    $on = $workflow['on'] ?? [];
    $events = match (true) {
        is_string($on) => [$on],
        is_array($on) && array_is_list($on) => $on,
        is_array($on) => array_keys($on),
        default => [],
    };

    return array_intersect($events, OUTSIDER_TRIGGERS) !== [];
}

/**
 * @param  array<mixed>  $workflow
 * @param  Closure(string): array<mixed>  $load
 * @return list<string>
 */
function selfHostedJobs(array $workflow, Closure $load): array
{
    $found = [];
    foreach (arrayOf($workflow['jobs'] ?? []) as $name => $job) {
        $uses = data_get($job, 'uses');
        if (is_string($uses) && str_starts_with($uses, './')) {
            foreach (selfHostedJobs($load(substr($uses, 2)), $load) as $inner) {
                $found[] = "{$name} > {$inner}";
            }
        }
        $runsOn = (string) json_encode(data_get($job, 'runs-on'));
        if (str_contains($runsOn, 'self-hosted') || str_contains($runsOn, 'homelab')) {
            $found[] = (string) $name;
        }
    }

    return $found;
}

/**
 * @param  array<mixed>  $workflow
 * @param  array<string, array<mixed>>  $locals
 * @return list<string>
 */
function selfHostedJobsOutsidersCanReach(array $workflow, array $locals = []): array
{
    return outsiderCanTrigger($workflow)
        ? selfHostedJobs($workflow, fn (string $path): array => $locals[$path] ?? workflowFile($path))
        : [];
}

it('keeps every self-hosted job off workflows an outside contributor can trigger', function (): void {
    $offenders = [];
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        $path = '.github/workflows/'.basename($file);
        foreach (selfHostedJobsOutsidersCanReach(workflowFile($path)) as $job) {
            $offenders[] = "{$path}: {$job}";
        }
    }

    expect($offenders)->toBe([]);
})->group('structure');

it('flags a self-hosted job on an outsider-triggered workflow, directly or through a local reusable workflow', function (mixed $on, array $jobs, array $locals, array $expected): void {
    expect(selfHostedJobsOutsidersCanReach(['on' => $on, 'jobs' => $jobs], $locals))->toBe($expected);
})->with([
    'direct label' => [['pull_request' => null], ['probe' => ['runs-on' => ['self-hosted', 'homelab']]], [], ['probe']],
    'string trigger' => ['issue_comment', ['probe' => ['runs-on' => 'homelab']], [], ['probe']],
    'reusable workflow' => [
        ['pull_request_target'],
        ['call' => ['uses' => './.github/workflows/inner.yml']],
        ['.github/workflows/inner.yml' => ['jobs' => ['deep' => ['runs-on' => ['self-hosted']]]]],
        ['call > deep'],
    ],
    'hosted runner' => [['pull_request' => null], ['probe' => ['runs-on' => 'ubuntu-26.04-arm']], [], []],
    'owner-only trigger' => [['schedule' => [['cron' => '0 0 * * *']], 'workflow_dispatch' => null], ['probe' => ['runs-on' => ['self-hosted', 'homelab']]], [], []],
])->group('structure');
