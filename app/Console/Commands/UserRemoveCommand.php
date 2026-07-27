<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\User\UserEraser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:remove {id : The user id to permanently remove} {--force : Skip the confirmation prompt}')]
#[Description('Permanently remove a user and all owned data (runs, cards, narration). Keeps ai_token_usages for cost history.')]
class UserRemoveCommand extends Command
{
    public function __construct(private readonly UserEraser $eraser)
    {
        parent::__construct();
    }


    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $user = User::query()->find($id);

        if ($user === null) {
            $this->error("User {$id} not found.");

            return self::FAILURE;
        }

        if ($user->is_demo) {
            $this->error("Refusing to remove the demo user (id {$id}). Reset it with `demo:seed` instead.");

            return self::FAILURE;
        }

        $activityIds = Activity::query()->where('user_id', $id)->pluck('id');
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');
        $snapshotIds = WeeklySnapshot::query()->where('user_id', $id)->pluck('id');
        $personalRecordIds = PersonalRecord::query()->where('user_id', $id)->pluck('id');

        $orphans = $this->eraser->orphanCounts($user);
        $tokenUsageCount = TokenUsage::query()->where('user_id', $id)->count();

        $this->table(['What', 'Count'], [
            ['User', "{$user->name} <{$user->email}> (id {$id})"],
            ['Activities (+ details, streams, cards, PRs, story lines)', (string) $activityIds->count()],
            ['Run cards', (string) $cardIds->count()],
            ['Weekly snapshots', (string) $snapshotIds->count()],
            ['Personal records', (string) $personalRecordIds->count()],
            ['AI analyses (deleted)', (string) $orphans['ai_analyses']],
            ['Push subscriptions (deleted)', (string) $orphans['push_subscriptions']],
            ['AI token-usage rows (KEPT, will orphan)', (string) $tokenUsageCount],
        ]);

        if (! $this->option('force')) {
            // isInteractive() alone only flips false for an explicit --no-interaction
            // flag: a bare `docker exec` (no -it) still reports interactive=true, so
            // confirm() reads immediate EOF as its "no" default and the command exits
            // 0 having silently done nothing. Also require a real stdin TTY (skipped
            // under tests, whose own stdin is never a TTY either) to catch that case.
            $hasRealTerminal = $this->laravel->runningUnitTests()
                || (defined('STDIN') && stream_isatty(STDIN));

            if (! $this->input->isInteractive() || ! $hasRealTerminal) {
                $this->error('No interactive terminal to confirm on. Re-run with a TTY (docker exec -it ...) or pass --force to skip the prompt.');

                return self::FAILURE;
            }

            if (! $this->confirm("Permanently remove user {$id} and all owned data? This cannot be undone.")) {
                $this->info('Aborted, nothing removed.');

                return self::SUCCESS;
            }
        }

        $this->eraser->erase($user);

        $this->info("Removed user {$id}. Kept {$tokenUsageCount} ai_token_usages row(s) for cost history (now orphaned under the old id).");

        return self::SUCCESS;
    }

}
