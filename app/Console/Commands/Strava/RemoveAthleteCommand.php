<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\User;
use App\Services\Strava\StravaClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:remove-athlete {user : The user id whose Strava grant to release} {--force : Skip the confirmation prompt}')]
#[Description('Release a user\'s Strava grant on Strava and revoke the local connection, keeping the account and its runs.')]
class RemoveAthleteCommand extends Command
{
    public function __construct(private readonly StravaClient $client)
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

        if ($user->is_demo) {
            $this->error("Refusing to release the demo user's connection (id {$id}). Reset it with `demo:seed` instead.");

            return self::FAILURE;
        }

        $connection = $user->stravaConnection;

        if ($connection === null || $connection->isRevoked()) {
            $this->info("User {$id} holds no live Strava grant. Nothing to release.");

            return self::SUCCESS;
        }

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

            if (! $this->confirm("Release {$user->name} <{$user->email}> (id {$id}) from Strava? They will have to reconnect to sync again.")) {
                $this->info('Aborted, nothing released.');

                return self::SUCCESS;
            }
        }

        $released = $this->client->deauthorize($connection);

        // Revoked locally either way: the operator asked for this athlete to
        // stop syncing, and leaving a live connection behind a refused call
        // would keep spending reads on them.
        $connection->markRevoked();

        $released
            ? $this->info("Released user {$id} on Strava and revoked the local connection.")
            : $this->warn("Strava did not accept the deauthorize for user {$id}; the local connection is revoked anyway. Check the log, and free the slot from Strava's settings if it is still held.");

        return self::SUCCESS;
    }
}
