<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * `vitest --changed` with no argument diffs the working tree against HEAD, so on
 * a clean checkout it selects nothing and exits 0.
 *
 * Pins that a base is resolved, and that an unresolvable one is a non-zero exit
 * rather than a silent empty string.
 *
 * Every scenario that needs a real `origin/main` builds its own throwaway repo:
 * CI's checkout is a shallow `fetch-depth: 1` clone with no `origin/main` ref.
 */
const BASE_SCRIPT = 'scripts/vitest-changed-base.sh';

/**
 * A stale worktree stack can still export GIT_DIR/GIT_WORK_TREE from a
 * compose.override.yaml. Every git command a test spawns against a throwaway
 * repo has to unset both, or it silently operates on the real checkout. A
 * GATE_BASE exported by the caller would likewise override every scenario.
 *
 * @return array{GIT_DIR: false, GIT_WORK_TREE: false, GATE_BASE: false}
 */
function withoutAmbientGitDir(): array
{
    return ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GATE_BASE' => false];
}

/**
 * A throwaway git repo with two commits: `origin/main` (a remote-tracking ref,
 * no actual remote needed) pointing at the first, HEAD at the second. Their
 * common ancestor is deterministic, so a test can assert the exact base the
 * script resolves rather than merely "looks like a sha".
 *
 * @return array{dir: string, base: string, head: string}
 */
function makeRepoWithOriginMain(): array
{
    $dir = sys_get_temp_dir().'/vitest-changed-base-repo-'.uniqid();
    mkdir($dir, recursive: true);

    $git = fn (string ...$args) => new Process(['git', ...$args], $dir, withoutAmbientGitDir())->mustRun();
    $commit = fn (string $message) => $git('-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '--allow-empty', '-q', '-m', $message);

    $git('init', '-q');
    $commit('base');
    $base = trim($git('rev-parse', 'HEAD')->getOutput());
    $git('update-ref', 'refs/remotes/origin/main', $base);
    $commit('head');
    $head = trim($git('rev-parse', 'HEAD')->getOutput());

    return ['dir' => $dir, 'base' => $base, 'head' => $head];
}

function installBaseScript(string $dir): void
{
    mkdir("$dir/scripts", recursive: true);
    copy(base_path(BASE_SCRIPT), "$dir/scripts/vitest-changed-base.sh");
}

it('prints a commit that vitest can diff against, naming the ref it came from', function (): void {
    $repo = makeRepoWithOriginMain();
    installBaseScript($repo['dir']);

    try {
        $process = new Process(['sh', "{$repo['dir']}/scripts/vitest-changed-base.sh"], $repo['dir'], withoutAmbientGitDir());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($repo['base'])
            ->and($process->getErrorOutput())->toContain('vitest --changed base: origin/main');
    } finally {
        exec('rm -rf '.escapeshellarg($repo['dir']));
    }
})->group('structure');

/**
 * A worktree's `.git` is a *file* naming an absolute path, and the point of the
 * same-path git mount is that git resolves it with no environment help at all.
 * This stages a throwaway checkout whose pointer is readable and asserts the
 * script reads the repo behind it — the mount's contract, minus Docker.
 */
it('resolves a base through a worktree-style .git pointer file, with no git environment', function (): void {
    $upstream = makeRepoWithOriginMain();
    $gitDir = trim(new Process(['git', 'rev-parse', '--absolute-git-dir'], $upstream['dir'], withoutAmbientGitDir())->mustRun()->getOutput());
    $checkout = sys_get_temp_dir().'/vitest-changed-base-'.uniqid();

    installBaseScript($checkout);
    file_put_contents("$checkout/.git", "gitdir: $gitDir\n");

    try {
        $process = new Process(['sh', "$checkout/scripts/vitest-changed-base.sh"], $checkout, withoutAmbientGitDir());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($upstream['base']);
    } finally {
        exec('rm -rf '.escapeshellarg($checkout));
        exec('rm -rf '.escapeshellarg($upstream['dir']));
    }
})->group('structure');

it('fails loudly when no base can be resolved', function (): void {
    $process = new Process(['sh', base_path(BASE_SCRIPT)], base_path(), ['GIT_DIR' => '/nonexistent']);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('no base commit');
})->group('structure');

/**
 * Adds an `origin/epic/x` ref one commit past `origin/main` and a branch commit
 * on top of it, so the three possible bases (main, epic, none) are distinct commits.
 *
 * @param  array{dir: string, base: string, head: string}  $repo
 * @return array{epic: string}
 */
function addEpicBranch(array $repo): array
{
    $git = fn (string ...$args) => new Process(['git', ...$args], $repo['dir'], withoutAmbientGitDir())->mustRun();

    $git('update-ref', 'refs/remotes/origin/epic/x', $repo['head']);
    $git('-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '--allow-empty', '-q', '-m', 'slice');

    return ['epic' => $repo['head']];
}

/**
 * @param  array<string, string>  $env
 */
function runBaseScript(string $dir, array $env = []): Process
{
    installBaseScript($dir);
    $process = new Process(['sh', "$dir/scripts/vitest-changed-base.sh"], $dir, array_merge(withoutAmbientGitDir(), $env));
    $process->run();

    return $process;
}

it('diffs against the ref in GATE_BASE, ahead of every other source', function (): void {
    $repo = makeRepoWithOriginMain();
    $epic = addEpicBranch($repo)['epic'];

    try {
        $branch = trim(new Process(['git', 'branch', '--show-current'], $repo['dir'], withoutAmbientGitDir())->mustRun()->getOutput());
        new Process(['git', 'config', "branch.$branch.gh-merge-base", 'main'], $repo['dir'], withoutAmbientGitDir())->mustRun();
        $process = runBaseScript($repo['dir'], ['GATE_BASE' => 'origin/epic/x']);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($epic)
            ->and($process->getErrorOutput())->toContain('vitest --changed base: GATE_BASE=origin/epic/x');
    } finally {
        exec('rm -rf '.escapeshellarg($repo['dir']));
    }
})->group('structure');

it('fails rather than falling back to main when GATE_BASE does not resolve', function (): void {
    $repo = makeRepoWithOriginMain();

    try {
        $process = runBaseScript($repo['dir'], ['GATE_BASE' => 'origin/no-such-branch']);

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('GATE_BASE=origin/no-such-branch');
    } finally {
        exec('rm -rf '.escapeshellarg($repo['dir']));
    }
})->group('structure');

it("follows the branch's recorded PR base when the remote ref is present locally", function (): void {
    $repo = makeRepoWithOriginMain();
    $epic = addEpicBranch($repo)['epic'];
    $branch = trim(new Process(['git', 'branch', '--show-current'], $repo['dir'], withoutAmbientGitDir())->mustRun()->getOutput());

    try {
        new Process(['git', 'config', "branch.$branch.gh-merge-base", 'epic/x'], $repo['dir'], withoutAmbientGitDir())->mustRun();
        $process = runBaseScript($repo['dir']);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($epic)
            ->and($process->getErrorOutput())->toContain('vitest --changed base: origin/epic/x');
    } finally {
        exec('rm -rf '.escapeshellarg($repo['dir']));
    }
})->group('structure');

it('falls back to origin/main when the recorded PR base is not fetched locally', function (): void {
    $repo = makeRepoWithOriginMain();
    $branch = trim(new Process(['git', 'branch', '--show-current'], $repo['dir'], withoutAmbientGitDir())->mustRun()->getOutput());

    try {
        new Process(['git', 'config', "branch.$branch.gh-merge-base", 'epic/unfetched'], $repo['dir'], withoutAmbientGitDir())->mustRun();
        $process = runBaseScript($repo['dir']);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($repo['base'])
            ->and($process->getErrorOutput())->toContain('vitest --changed base: origin/main');
    } finally {
        exec('rm -rf '.escapeshellarg($repo['dir']));
    }
})->group('structure');
