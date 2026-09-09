<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Console\Commands\Concerns\ConfirmsPermanentRemoval;
use App\Models\Activity;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\User\UserEraser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:remove-athlete {user : The user id to release on Strava and permanently remove} {--force : Skip the confirmation prompt}')]
#[Description('Release a user\'s Strava grant on Strava, then permanently remove the account and all owned data. Keeps ai_token_usages for cost history.')]
class RemoveAthleteCommand extends Command
{
    use ConfirmsPermanentRemoval;

    public function __construct(private readonly UserEraser $eraser)
    {
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

        if ($this->refuseDemoUser($user)) {
            return self::FAILURE;
        }

        $connection = $user->stravaConnection;
        $liveGrant = $connection !== null && ! $connection->isRevoked() ? $connection : null;

        $activityCount = Activity::query()->where('user_id', $id)->count();
        $tokenUsageCount = TokenUsage::query()->where('user_id', $id)->count();
        $orphans = $this->eraser->orphanCounts($user);

        $this->table(['What', 'Count'], [
            $this->accountRow($user),
            ['Strava grant', $liveGrant === null ? 'none live, nothing to release' : 'live, will be released'],
            ['Activities (+ details, streams, cards, PRs, story lines)', (string) $activityCount],
            ...$this->orphanRows($orphans, $tokenUsageCount),
        ]);

        if (($exit = $this->confirmRemoval(
            "Release {$user->name} <{$user->email}> (id {$id}) from Strava and permanently remove the account and all owned data? This cannot be undone.",
            'Aborted, nothing released or removed.',
        )) !== null) {
            return $exit;
        }

        // Called here rather than left to erase() alone so the operator is
        // told whether Strava took it; erase() then meets a revoked
        // connection and skips its own best-effort release.
        match ($this->eraser->releaseStravaGrant($user)) {
            null => $this->info("User {$id} holds no live Strava grant, so there was nothing to release."),
            true => $this->info("Released user {$id} on Strava."),
            false => $this->warn("Strava did not accept the deauthorize for user {$id}. Check the log, and free the slot from Strava's settings if it is still held."),
        };

        $this->eraser->erase($user);

        $this->info("Removed user {$id} and all owned data. ".$this->tokenUsageKeptMessage($tokenUsageCount));

        return self::SUCCESS;
    }
}
