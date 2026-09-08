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
 */
const BASE_SCRIPT = 'scripts/vitest-changed-base.sh';

function runBaseScript(?array $env = null): Process
{
    $process = new Process(['sh', base_path(BASE_SCRIPT)], base_path(), $env);
    $process->run();

    return $process;
}

function expectResolvedBase(Process $process): void
{
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))->toMatch('/^[0-9a-f]{40}$/');
}

it('prints a commit that vitest can diff against, naming the ref it came from', function (): void {
    $process = runBaseScript();

    expectResolvedBase($process);
    expect($process->getErrorOutput())->toContain('vitest --changed base:');
});

/**
 * Merely unsetting GIT_DIR/GIT_WORK_TREE proves nothing on an ordinary checkout:
 * git falls back to discovering the real `.git` by walking up from cwd, so the
 * script would resolve a base even with the TEMARI_GIT_DIR restore deleted. The
 * failure this fix targets needs a checkout whose `.git` is a *file* pointing at
 * a path the runtime cannot read — so this stages a throwaway one, scripts and
 * broken pointer alike, and hands the real git dir back only via TEMARI_GIT_DIR.
 * It stays a plain directory outside the repo: registering a real linked
 * worktree would put `git worktree prune` in the cleanup path, and inside a
 * container that only bind-mounts the shared git dir prune deletes the admin
 * directory of every worktree whose checkout it cannot see.
 */
it('still resolves a base once Composer has stripped the git environment', function (): void {
    $gitDir = trim((new Process(['git', 'rev-parse', '--absolute-git-dir'], base_path()))->mustRun()->getOutput());
    $checkout = sys_get_temp_dir().'/vitest-changed-base-'.uniqid();

    mkdir("$checkout/scripts", recursive: true);
    file_put_contents("$checkout/.git", "gitdir: /nonexistent-container-path\n");
    copy(base_path('scripts/git-env.sh'), "$checkout/scripts/git-env.sh");
    copy(base_path(BASE_SCRIPT), "$checkout/scripts/vitest-changed-base.sh");

    try {
        $process = new Process(
            ['sh', "$checkout/scripts/vitest-changed-base.sh"],
            $checkout,
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'TEMARI_GIT_DIR' => $gitDir],
        );
        $process->run();

        expectResolvedBase($process);
    } finally {
        exec('rm -rf '.escapeshellarg($checkout));
    }
});

it('fails loudly when no base can be resolved', function (): void {
    $process = runBaseScript(['GIT_DIR' => '/nonexistent', 'TEMARI_GIT_DIR' => '/nonexistent']);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('no base commit');
});

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
});
