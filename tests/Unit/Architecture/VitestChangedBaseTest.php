<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * `vitest --changed` with no argument diffs the working tree against HEAD, so on
 * a clean checkout it selects nothing and exits 0. The gate used to fall back to
 * exactly that whenever `git merge-base HEAD origin/main` failed, so it ran zero
 * frontend tests and reported the step green.
 *
 * This pins both halves of the fix: a base is resolved, and an unresolvable one
 * is a non-zero exit rather than a silent empty string.
 *
 * Every scenario that needs a real `origin/main` builds its own throwaway repo
 * rather than relying on this checkout's: CI's own checkout is a shallow
 * `fetch-depth: 1` clone with no `origin/main` ref at all, so asserting against
 * this repo's actual remote-tracking state would fail there for the same reason
 * the script itself would.
 */
const BASE_SCRIPT = 'scripts/vitest-changed-base.sh';

/**
 * A stale worktree stack can still export GIT_DIR/GIT_WORK_TREE from a
 * compose.override.yaml written before the same-path git mount. Every git
 * command a test spawns against a throwaway repo has to unset both, or it
 * silently operates on the real checkout instead — it bit this file for real
 * once already.
 *
 * @return array{GIT_DIR: false, GIT_WORK_TREE: false}
 */
function withoutAmbientGitDir(): array
{
    return ['GIT_DIR' => false, 'GIT_WORK_TREE' => false];
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

    return compact('dir', 'base', 'head');
}

it('prints a commit that vitest can diff against, naming the ref it came from', function (): void {
    $repo = makeRepoWithOriginMain();
    mkdir("{$repo['dir']}/scripts", recursive: true);
    copy(base_path(BASE_SCRIPT), "{$repo['dir']}/scripts/vitest-changed-base.sh");

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

    mkdir("$checkout/scripts", recursive: true);
    copy(base_path(BASE_SCRIPT), "$checkout/scripts/vitest-changed-base.sh");
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
 * The TIA guard in tests/Pest.php has to be true in both layouts, or a worktree
 * silently runs with TIA off.
 */
it('recognises both a plain .git directory and a worktree pointer file', function (): void {
    $dir = sys_get_temp_dir().'/git-guard-'.uniqid();
    mkdir("$dir/plain/.git", recursive: true);
    mkdir("$dir/pointer", recursive: true);
    mkdir("$dir/dangling", recursive: true);
    file_put_contents("$dir/pointer/.git", "gitdir: $dir/plain/.git\n");
    file_put_contents("$dir/dangling/.git", "gitdir: $dir/nowhere\n");

    try {
        expect(gitCanReadRepository("$dir/plain"))->toBeTrue()
            ->and(gitCanReadRepository("$dir/pointer"))->toBeTrue()
            ->and(gitCanReadRepository("$dir/dangling"))->toBeFalse()
            ->and(gitCanReadRepository("$dir/missing"))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
})->group('structure');
