<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\Activity;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\Strava\StravaClient;
use App\Services\User\UserEraser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:remove-athlete {user : The user id to release on Strava and permanently remove} {--force : Skip the confirmation prompt}')]
#[Description('Release a user\'s Strava grant on Strava, then permanently remove the account and all owned data. Keeps ai_token_usages for cost history.')]
class RemoveAthleteCommand extends Command
{
    public function __construct(
        private readonly StravaClient $client,
        private readonly UserEraser $eraser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = (int) $this->argument('user');
        $user = User::query()->find($id);

        if ($user === null) {
            $this->error("User {$id} not found.");

            return self::FAILURE;
        }

        if ($user->is_demo) {
            $this->error("Refusing to remove the demo user (id {$id}). Reset it with `demo:seed` instead.");

            return self::FAILURE;
        }

        $connection = $user->stravaConnection;
        $liveGrant = $connection !== null && ! $connection->isRevoked() ? $connection : null;

        $activityCount = Activity::query()->where('user_id', $id)->count();
        $tokenUsageCount = TokenUsage::query()->where('user_id', $id)->count();
        $orphans = $this->eraser->orphanCounts($user);

        $this->table(['What', 'Count'], [
            ['User', "{$user->name} <{$user->email}> (id {$id})"],
            ['Strava grant', $liveGrant === null ? 'none live, nothing to release' : 'live, will be released'],
            ['Activities (+ details, streams, cards, PRs, story lines)', (string) $activityCount],
            ['AI analyses (deleted)', (string) $orphans['ai_analyses']],
            ['Push subscriptions (deleted)', (string) $orphans['push_subscriptions']],
            ['AI token-usage rows (KEPT, will orphan)', (string) $tokenUsageCount],
        ]);

        if (! $this->option('force')) {
            // Same trap as user:remove: a bare `docker exec` without -it still
            // reports interactive=true, so confirm() would read EOF as its "no"
            // default and exit 0 having done nothing.
            $hasRealTerminal = $this->laravel->runningUnitTests()
                || (defined('STDIN') && stream_isatty(STDIN));

            if (! $this->input->isInteractive() || ! $hasRealTerminal) {
                $this->error('No interactive terminal to confirm on. Re-run with a TTY (docker exec -it ...) or pass --force to skip the prompt.');

                return self::FAILURE;
            }

            if (! $this->confirm("Release {$user->name} <{$user->email}> (id {$id}) from Strava and permanently remove the account and all owned data? This cannot be undone.")) {
                $this->info('Aborted, nothing released or removed.');

                return self::SUCCESS;
            }
        }

        if ($liveGrant !== null) {
            // Released here rather than left to UserEraser so the operator is
            // told whether Strava actually took it. Revoked locally either way,
            // and the eraser then skips its own best-effort release.
            $released = $this->client->deauthorize($liveGrant);
            $liveGrant->markRevoked();

            $released
                ? $this->info("Released user {$id} on Strava.")
                : $this->warn("Strava did not accept the deauthorize for user {$id}. Check the log, and free the slot from Strava's settings if it is still held.");
        } else {
            $this->info("User {$id} holds no live Strava grant, so there was nothing to release.");
        }

        $this->eraser->erase($user);

        $this->info("Removed user {$id} and all owned data. Kept {$tokenUsageCount} ai_token_usages row(s) for cost history (now orphaned under the old id).");

        return self::SUCCESS;
    }
}
