<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ConfirmsPermanentRemoval;
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
    use ConfirmsPermanentRemoval;

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

        if ($this->refuseDemoUser($user)) {
            return self::FAILURE;
        }

        $activityIds = Activity::query()->where('user_id', $id)->pluck('id');
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');
        $snapshotIds = WeeklySnapshot::query()->where('user_id', $id)->pluck('id');
        $personalRecordIds = PersonalRecord::query()->where('user_id', $id)->pluck('id');

        $orphans = $this->eraser->orphanCounts($user);
        $tokenUsageCount = TokenUsage::query()->where('user_id', $id)->count();

        $this->table(['What', 'Count'], [
            $this->accountRow($user),
            ['Activities (+ details, streams, cards, PRs, story lines)', (string) $activityIds->count()],
            ['Run cards', (string) $cardIds->count()],
            ['Weekly snapshots', (string) $snapshotIds->count()],
            ['Personal records', (string) $personalRecordIds->count()],
            ...$this->orphanRows($orphans, $tokenUsageCount),
        ]);

        if (($exit = $this->confirmRemoval(
            "Permanently remove user {$id} and all owned data? This cannot be undone.",
            'Aborted, nothing removed.',
        )) !== null) {
            return $exit;
        }

        $this->eraser->erase($user);

        $this->info("Removed user {$id}. ".$this->tokenUsageKeptMessage($tokenUsageCount));

        return self::SUCCESS;
    }

}
