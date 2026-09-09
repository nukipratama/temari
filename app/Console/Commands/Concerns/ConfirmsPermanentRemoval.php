<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\User;

/**
 * The demo refusal, confirm-or-abort gate, and closing token-usage note are
 * identical between {@see \App\Console\Commands\UserRemoveCommand} and
 * {@see \App\Console\Commands\Strava\RemoveAthleteCommand} — both permanently
 * remove a user through {@see \App\Services\User\UserEraser}.
 */
trait ConfirmsPermanentRemoval
{
    /**
     * True when the user is the demo account, having already printed the
     * refusal. `demo:seed` is that account's own reset path, not a delete.
     */
    private function refuseDemoUser(User $user): bool
    {
        if (! $user->is_demo) {
            return false;
        }

        $this->error("Refusing to remove the demo user (id {$user->id}). Reset it with `demo:seed` instead.");

        return true;
    }

    /**
     * Confirms the removal unless `--force` is set, returning null to
     * proceed or the exit code to return immediately.
     *
     * A bare `docker exec` without `-it` still reports `isInteractive() ===
     * true`, so a plain `confirm()` would read immediate EOF as its "no"
     * default and exit 0 having silently done nothing — this also requires
     * a real stdin TTY (skipped under tests, whose own stdin is never a TTY
     * either) to catch that case.
     */
    private function confirmRemoval(string $question, string $abortMessage): ?int
    {
        if ($this->option('force')) {
            return null;
        }

        $hasRealTerminal = $this->laravel->runningUnitTests()
            || (defined('STDIN') && stream_isatty(STDIN));

        if (! $this->input->isInteractive() || ! $hasRealTerminal) {
            $this->error('No interactive terminal to confirm on. Re-run with a TTY (docker exec -it ...) or pass --force to skip the prompt.');

            return self::FAILURE;
        }

        if (! $this->confirm($question)) {
            $this->info($abortMessage);

            return self::SUCCESS;
        }

        return null;
    }

    /** @return array{string, string} */
    private function accountRow(User $user): array
    {
        return ['User', "{$user->name} <{$user->email}> (id {$user->id})"];
    }

    /**
     * The orphan and cost-history rows every removal preview ends with.
     *
     * @param  array{ai_analyses: int, push_subscriptions: int}  $orphans
     * @return list<array{string, string}>
     */
    private function orphanRows(array $orphans, int $tokenUsageCount): array
    {
        return [
            ['AI analyses (deleted)', (string) $orphans['ai_analyses']],
            ['Push subscriptions (deleted)', (string) $orphans['push_subscriptions']],
            ['AI token-usage rows (KEPT, will orphan)', (string) $tokenUsageCount],
        ];
    }

    private function tokenUsageKeptMessage(int $tokenUsageCount): string
    {
        return "Kept {$tokenUsageCount} ai_token_usages row(s) for cost history (now orphaned under the old id).";
    }
}
