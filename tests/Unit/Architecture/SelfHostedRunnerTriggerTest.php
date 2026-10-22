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
 * Keyed the way workflow_run matches: by `name:`, or by file path for a workflow without one.
 *
 * @return array<string, array<mixed>>
 */
function repoWorkflowsByName(): array
{
    $byName = [];
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        $path = '.github/workflows/'.basename($file);
        $workflow = workflowFile($path);
        $name = data_get($workflow, 'name');
        $byName[is_string($name) ? $name : $path] = $workflow;
    }

    return $byName;
}

function requiresWatchedPush(mixed $if): bool
{
    if (! is_string($if)) {
        return false;
    }

    $condition = trim(preg_replace('/^\$\{\{(.*)\}\}$/s', '$1', trim($if)) ?? '');
    if (str_contains($condition, '||')) {
        return false;
    }

    $terms = array_map(trim(...), explode('&&', $condition));

    return in_array("github.event.workflow_run.event == 'push'", $terms, true);
}

/**
 * @param  array<mixed>  $workflow
 * @param  array<string, array<mixed>>  $byName
 * @param  list<string>  $seen
 */
function outsiderCanTriggerThroughRun(array $workflow, array $byName, array $seen = []): bool
{
    foreach (arrayOf(data_get($workflow, 'on.workflow_run.workflows', [])) as $name) {
        if (! is_string($name) || in_array($name, $seen, true)) {
            continue;
        }
        $watched = $byName[$name] ?? null;
        if ($watched === null) {
            continue;
        }
        if (outsiderCanTrigger($watched) || outsiderCanTriggerThroughRun($watched, $byName, [...$seen, $name])) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<mixed>  $workflow
 * @param  array<string, array<mixed>>  $locals
 * @param  array<string, array<mixed>>  $byName
 * @return list<string>
 */
function selfHostedJobsOutsidersCanReach(array $workflow, array $locals = [], array $byName = []): array
{
    $load = fn (string $path): array => $locals[$path] ?? workflowFile($path);

    if (outsiderCanTrigger($workflow)) {
        return selfHostedJobs($workflow, $load);
    }

    if (! outsiderCanTriggerThroughRun($workflow, $byName)) {
        return [];
    }

    // A watched run that an outsider started is never a push to this repository.
    $unguarded = array_filter(
        arrayOf($workflow['jobs'] ?? []),
        fn (mixed $job): bool => ! requiresWatchedPush(data_get($job, 'if')),
    );

    return selfHostedJobs(['jobs' => $unguarded], $load);
}

it('keeps every self-hosted job off workflows an outside contributor can trigger', function (): void {
    $byName = repoWorkflowsByName();
    $offenders = [];
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        $path = '.github/workflows/'.basename($file);
        foreach (selfHostedJobsOutsidersCanReach(workflowFile($path), byName: $byName) as $job) {
            $offenders[] = "{$path}: {$job}";
        }
    }

    expect($offenders)->toBe([]);
})->group('structure');

it('follows a workflow_run trigger to the workflow it watches', function (array $watched, mixed $if, array $expected): void {
    $workflow = [
        'on' => ['workflow_run' => ['workflows' => ['Watched'], 'types' => ['completed']]],
        'jobs' => ['probe' => array_filter(['if' => $if, 'runs-on' => ['self-hosted', 'homelab']])],
    ];

    expect(selfHostedJobsOutsidersCanReach($workflow, byName: ['Watched' => $watched]))->toBe($expected);
})->with([
    'watches an outsider-triggered workflow' => [['on' => ['pull_request' => null]], null, ['probe']],
    'guarded to the watched run being a push' => [['on' => ['pull_request' => null]], "github.event.workflow_run.event == 'push'", []],
    'push guard alongside another condition' => [['on' => ['pull_request' => null]], "\${{ github.event.workflow_run.conclusion == 'success' && github.event.workflow_run.event == 'push' }}", []],
    'push guard ORed with another event' => [['on' => ['pull_request' => null]], "github.event.workflow_run.event == 'push' || github.event.workflow_run.event == 'pull_request'", ['probe']],
    'push guard negated' => [['on' => ['pull_request' => null]], "github.event.workflow_run.event != 'push'", ['probe']],
    'watches an owner-only workflow' => [['on' => ['schedule' => [['cron' => '0 0 * * *']]]], null, []],
    'a watch cycle with no outsider trigger' => [
        ['on' => ['workflow_run' => ['workflows' => ['Watched']]]],
        null,
        [],
    ],
])->group('structure');

it('finds a watched workflow without a name by its file path', function (): void {
    expect(repoWorkflowsByName())->toHaveCount(count(glob(base_path('.github/workflows/*.yml')) ?: []));

    $workflow = [
        'on' => ['workflow_run' => ['workflows' => ['.github/workflows/unnamed.yml']]],
        'jobs' => ['probe' => ['runs-on' => ['self-hosted', 'homelab']]],
    ];

    expect(selfHostedJobsOutsidersCanReach($workflow, byName: ['.github/workflows/unnamed.yml' => ['on' => ['pull_request' => null]]]))->toBe(['probe']);
})->group('structure');

it('reaches the nightly backup only from the owner, so ops-disk stays safe on the homelab', function (): void {
    $byName = repoWorkflowsByName();

    expect(outsiderCanTriggerThroughRun(workflowFile('.github/workflows/ops-disk.yml'), $byName))->toBeFalse()
        ->and(outsiderCanTriggerThroughRun(workflowFile('.github/workflows/release-deploy.yml'), $byName))->toBeTrue()
        ->and(selfHostedJobsOutsidersCanReach(workflowFile('.github/workflows/release-deploy.yml'), byName: $byName))->toBe([]);
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
