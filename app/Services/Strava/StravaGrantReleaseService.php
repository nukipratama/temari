<?php

declare(strict_types=1);

namespace App\Services\Strava;

use App\Enums\StravaGrantReleaseStatus;
use App\Models\StravaGrantToken;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class StravaGrantReleaseService
{
    public function __construct(
        private StravaClient $client,
        private StravaGrantLedger $ledger,
    ) {
    }

    public function release(
        int $stravaAthleteId,
        bool $forced = false,
        ?int $expectedCredentialVersion = null,
    ): ?StravaGrantReleaseResult {
        $grant = $this->ledger->currentGrantTokens()
            ->where('strava_athlete_id', $stravaAthleteId)
            ->when($expectedCredentialVersion !== null, fn ($query) => $query->where('credential_version', $expectedCredentialVersion))
            ->first();

        if ($grant === null) {
            return $expectedCredentialVersion === null
                ? null
                : new StravaGrantReleaseResult(StravaGrantReleaseStatus::Stale);
        }

        $result = $this->client->deauthorizeGrantToken($grant);

        if ($result->status === StravaGrantReleaseStatus::Stale) {
            return $result;
        }

        if (! $this->ledger->recordReleaseOutcome($grant, $result, $forced)) {
            return new StravaGrantReleaseResult(StravaGrantReleaseStatus::Stale);
        }

        return $result;
    }

    /** @return Collection<int, StravaGrantToken> */
    public function orphanGrants(): Collection
    {
        return $this->ledger->orphanGrantTokens()
            ->where(function ($query): void {
                $query->whereNull('user_id')
                    ->orWhereNotIn('user_id', User::query()->where('is_demo', true)->select('id'));
            })
            ->get();
    }

    public function retryOrphans(): void
    {
        foreach ($this->orphanGrants() as $grant) {
            $this->release(
                $grant->strava_athlete_id,
                expectedCredentialVersion: $grant->credential_version,
            );
        }
    }
}
