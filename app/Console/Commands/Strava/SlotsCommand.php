<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Enums\StravaGrantReleaseStatus;
use App\Models\StravaConnection;
use App\Models\StravaGrantToken;
use App\Models\User;
use App\Services\Strava\StravaGrantLedger;
use App\Services\Strava\StravaGrantReleaseResult;
use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use stdClass;

#[Signature('strava:slots
    {--release= : Release this Strava athlete id}
    {--release-orphans : Release all holders without an active connection}
    {--force : Skip the confirmation prompt}')]
#[Description('List current Strava grant holders or release a grant to free an athlete slot.')]
class SlotsCommand extends Command
{
    public function handle(StravaGrantLedger $ledger, StravaGrantReleaseService $releases): int
    {
        $releaseId = $this->option('release');
        $releaseOrphans = (bool) $this->option('release-orphans');

        if ($releaseId !== null && $releaseOrphans) {
            $this->error('Choose either --release or --release-orphans.');

            return self::FAILURE;
        }

        if ($releaseId !== null) {
            return $this->releaseOne((string) $releaseId, $ledger, $releases);
        }

        if ($releaseOrphans) {
            return $this->releaseOrphans($releases);
        }

        $demoUserIds = User::query()->where('is_demo', true)->pluck('id');
        $holders = $ledger->holderRows()->reject(fn (stdClass $holder): bool => $demoUserIds->contains($holder->user_id));
        $rows = $holders->map(function (stdClass $holder): array {
            $userId = $holder->user_id === null || ! User::query()->whereKey($holder->user_id)->exists()
                ? 'deleted'
                : (string) $holder->user_id;
            $connectionState = match (true) {
                $holder->connection_id === null => 'none',
                $holder->connection_revoked_at === null => 'active',
                default => 'revoked',
            };

            return [
                (string) $holder->strava_athlete_id,
                $userId,
                Carbon::parse($holder->granted_at)->format('Y-m-d H:i:s'),
                $connectionState,
                (string) $holder->release_attempts,
                $holder->last_error ?? '—',
            ];
        });

        $this->table(['Athlete ID', 'User ID', 'Granted at', 'Connection', 'Release attempts', 'Last error'], $rows);
        $this->line('Total: '.$holders->count());

        return self::SUCCESS;
    }

    private function releaseOne(string $releaseId, StravaGrantLedger $ledger, StravaGrantReleaseService $releases): int
    {
        $athleteId = filter_var($releaseId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($athleteId === false) {
            $this->error('--release must be a positive Strava athlete id.');

            return self::FAILURE;
        }

        $grant = $ledger->currentGrantTokens()->where('strava_athlete_id', $athleteId)->first();

        if ($grant === null) {
            $this->error("Strava athlete {$athleteId} is not a current holder.");

            return self::FAILURE;
        }

        if ($this->isDemoGrant($grant)) {
            $this->error('The demo user cannot be released.');

            return self::FAILURE;
        }

        if (! $this->confirmRelease("Release Strava athlete {$athleteId} and free its slot?")) {
            return self::SUCCESS;
        }

        $connection = StravaConnection::query()
            ->where('strava_athlete_id', $athleteId)
            ->whereNull('revoked_at')
            ->first();
        $result = $releases->release(
            $athleteId,
            forced: true,
            expectedCredentialVersion: $grant->credential_version,
        );

        if ($connection !== null && $result?->status !== StravaGrantReleaseStatus::Stale) {
            $connection->markRevoked(expectedCredentialVersion: $grant->credential_version);
        }

        $this->reportResult($athleteId, $result);

        return $result?->status === StravaGrantReleaseStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    private function releaseOrphans(StravaGrantReleaseService $releases): int
    {
        $grants = $releases->orphanGrants();

        if ($grants->isEmpty()) {
            $this->info('No orphaned Strava grants to release.');

            return self::SUCCESS;
        }

        if (! $this->confirmRelease('Release '.$grants->count().' orphaned Strava grants and free their slots?')) {
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($grants as $grant) {
            $result = $releases->release(
                $grant->strava_athlete_id,
                forced: true,
                expectedCredentialVersion: $grant->credential_version,
            );
            $this->reportResult($grant->strava_athlete_id, $result);
            $failed += $result?->status === StravaGrantReleaseStatus::Failed ? 1 : 0;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function isDemoGrant(StravaGrantToken $grant): bool
    {
        return $grant->user_id !== null
            && User::query()->whereKey($grant->user_id)->where('is_demo', true)->exists();
    }

    private function confirmRelease(string $question): bool
    {
        return (bool) $this->option('force') || $this->confirm($question, false);
    }

    private function reportResult(int $athleteId, ?StravaGrantReleaseResult $result): void
    {
        match ($result?->status) {
            StravaGrantReleaseStatus::Released => $this->info("Released Strava athlete {$athleteId}."),
            StravaGrantReleaseStatus::Rejected => $this->info("Strava athlete {$athleteId} was already free."),
            StravaGrantReleaseStatus::Failed => $this->warn("Release failed for Strava athlete {$athleteId}; the token is retained for retry. {$result->error}"),
            StravaGrantReleaseStatus::Stale => $this->info("Ignored a stale release result for Strava athlete {$athleteId}."),
            null => $this->info("Strava athlete {$athleteId} is no longer a holder."),
        };
    }
}
