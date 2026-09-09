<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * `vitest --changed` with no argument diffs the working tree against HEAD, so on
 * a clean checkout it selects nothing and exits 0. The gate used to fall back to
 * exactly that whenever `git merge-base HEAD origin/main` failed, and in a
 * worktree stack that failure is the normal case: Composer strips GIT_DIR and
 * GIT_WORK_TREE from the environment of every script it runs, and a worktree's
 * own .git is a file pointing at a host path outside the container's bind mount.
 * So `composer gate` ran zero frontend tests and reported the step green.
 *
 * This pins the three halves of the fix: a base is resolved, it survives the
 * environment Composer hands a script, and an unresolvable one is a non-zero
 * exit rather than a silent empty string.
 *
 * Every scenario that needs a real `origin/main` builds its own throwaway repo
 * rather than relying on this checkout's: CI's own checkout is a shallow
 * `fetch-depth: 1` clone with no `origin/main` ref at all, so asserting against
 * this repo's actual remote-tracking state would fail there for the same reason
 * the script itself would.
 */
const BASE_SCRIPT = 'scripts/vitest-changed-base.sh';

function runBaseScript(?array $env = null): Process
{
    $process = new Process(['sh', base_path(BASE_SCRIPT)], base_path(), $env);
    $process->run();

    return $process;
}

/**
 * A worktree stack (this repo included, see scripts/worktree-setup.sh) exports
 * GIT_DIR/GIT_WORK_TREE at the container level so TIA can resolve the shared
 * git dir. Every git command a test spawns against a throwaway repo has to
 * unset both, or it silently operates on the real checkout instead — which is
 * exactly the ambient env `vitest-changed-base.sh` itself has to fight (see
 * scripts/git-env.sh), and it bit this test file for real once already.
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

function copyBaseScriptInto(string $dir): void
{
    mkdir("$dir/scripts", recursive: true);
    copy(base_path('scripts/git-env.sh'), "$dir/scripts/git-env.sh");
    copy(base_path(BASE_SCRIPT), "$dir/scripts/vitest-changed-base.sh");
}

it('prints a commit that vitest can diff against, naming the ref it came from', function (): void {
    $repo = makeRepoWithOriginMain();
    copyBaseScriptInto($repo['dir']);

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
 * Merely unsetting GIT_DIR/GIT_WORK_TREE proves nothing on an ordinary checkout:
 * git falls back to discovering the real `.git` by walking up from cwd, so the
 * script would resolve a base even with the TEMARI_GIT_DIR restore deleted. The
 * failure this fix targets needs a checkout whose `.git` is a *file* pointing at
 * a path the runtime cannot read — so this stages a throwaway one, scripts and
 * broken pointer alike, and hands the real git dir back only via TEMARI_GIT_DIR.
 * It stays a plain directory outside any repo: registering a real linked
 * worktree would put `git worktree prune` in the cleanup path, and inside a
 * container that only bind-mounts the shared git dir prune deletes the admin
 * directory of every worktree whose checkout it cannot see.
 *
 * The "real" repo behind TEMARI_GIT_DIR is itself a throwaway one (not this
 * checkout's), so the assertion doesn't depend on this repo having an
 * `origin/main` ref either.
 */
it('still resolves a base once Composer has stripped the git environment', function (): void {
    $upstream = makeRepoWithOriginMain();
    $gitDir = trim(new Process(['git', 'rev-parse', '--absolute-git-dir'], $upstream['dir'], withoutAmbientGitDir())->mustRun()->getOutput());
    $checkout = sys_get_temp_dir().'/vitest-changed-base-'.uniqid();

    copyBaseScriptInto($checkout);
    file_put_contents("$checkout/.git", "gitdir: /nonexistent-container-path\n");

    try {
        $process = new Process(
            ['sh', "$checkout/scripts/vitest-changed-base.sh"],
            $checkout,
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'TEMARI_GIT_DIR' => $gitDir],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe($upstream['base']);
    } finally {
        exec('rm -rf '.escapeshellarg($checkout));
        exec('rm -rf '.escapeshellarg($upstream['dir']));
    }
})->group('structure');

it('fails loudly when no base can be resolved', function (): void {
    $process = runBaseScript(['GIT_DIR' => '/nonexistent', 'TEMARI_GIT_DIR' => '/nonexistent']);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('no base commit');
})->group('structure');

/**
 * A resolved base is only half of it: `vitest --changed` shells out to git
 * itself, so the restore has to reach the gate's own environment and not just
 * this script's. Without it vitest reports "No test files found" against a
 * perfectly good base, which the gate scores as a pass.
 */
it('restores the git environment for the whole gate, not just the base script', function (): void {
    $gate = (string) file_get_contents(base_path('scripts/gate.sh'));

    expect($gate)->toContain('. scripts/git-env.sh')
        ->and(strpos($gate, '. scripts/git-env.sh'))->toBeLessThan((int) strpos($gate, 'npx vitest'));
})->group('structure');
