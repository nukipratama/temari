<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\User;
use App\Services\Strava\StravaWebhookProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-stop health check for the Strava ingestion pipeline: per-athlete
 * connection + activity counts, plus the webhook callback self-handshake.
 * `--repair` re-queues activities stranded mid-pipeline (e.g. rows inserted
 * while the queue was down). Single-activity prior art: strava:resync-activity.
 */
#[Signature('strava:doctor
    {--user= : Only this user id; otherwise every connected athlete}
    {--repair : Re-dispatch ingestion for activities stuck without analysis}
    {--e2e : Run all checks and report pass/fail summary}')]
#[Description('Diagnose Strava connection + ingestion health (optionally repair stranded activities).')]
class DoctorCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('e2e')) {
            return $this->e2eCheck();
        }

        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->warn('No users with a Strava connection found.');

            return self::SUCCESS;
        }

        $this->reportUsers($users);

        if ($this->option('repair')) {
            $this->newLine();
            $this->repair($users);
        }

        $this->newLine();
        $this->reportWebhook();

        return self::SUCCESS;
    }

    private function e2eCheck(): int
    {
        $passes = 0;
        $failures = 0;

        $sampleUserId = $this->resolveUsers()->first()?->id;

        $checks = [
            'OAuth credentials' => function (): bool {
                $clientId = config('services.strava.client_id');
                $clientSecret = config('services.strava.client_secret');

                return filled($clientId) && filled($clientSecret);
            },
            'Active connections' => fn (): bool => User::query()
                ->whereHas('stravaConnection', fn (Builder $q): Builder => $q->whereNull('revoked_at'))
                ->exists(),
            'Webhook self-handshake' => function (): bool {
                $callbackUrl = route('strava.webhook.verify');
                $verifyToken = (string) config('services.strava.webhook_verify_token');

                return $verifyToken !== '' && app(StravaWebhookProbe::class)->probe($callbackUrl, $verifyToken)['passed'];
            },
            'Rate limit headroom (15 min)' => fn (): bool => $sampleUserId !== null && RateLimiter::remaining("strava-api:{$sampleUserId}:15min", 200) > 0,
            'Rate limit headroom (daily)' => fn (): bool => $sampleUserId !== null && RateLimiter::remaining("strava-api:{$sampleUserId}:daily", 2000) > 0,
            'No stranded activities' => fn (): bool => Activity::query()
                ->pendingIngest()
                ->whereHas('user.stravaConnection', fn (Builder $q): Builder => $q->whereNull('revoked_at'))
                ->doesntExist(),
        ];

        foreach ($checks as $label => $check) {
            if ($check()) {
                $this->info("  PASS  {$label}");
                $passes++;
            } else {
                $this->error("  FAIL  {$label}");
                $failures++;
            }
        }

        $this->newLine();
        $this->line("Results: {$passes} passed, {$failures} failed");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function reportUsers(Collection $users): void
    {
        $rows = $users->map(function (User $user): array {
            $connection = $user->stravaConnection;
            $counts = $this->activityCounts($user->id);
            $lastFetched = Activity::query()
                ->withStubs()
                ->where('user_id', $user->id)
                ->whereNotNull('fetched_at')
                ->max('fetched_at');

            return [
                $user->id,
                $this->connectionLabel($user),
                $connection?->token_expires_at?->toDateTimeString() ?? '—',
                $counts['total'],
                $counts['analyzed'],
                $counts['pending'],
                $counts['stranded'],
                is_string($lastFetched) ? $lastFetched : ($lastFetched?->toDateTimeString() ?? '—'),
            ];
        })->all();

        $this->table(
            ['user', 'connection', 'token_expires', 'total', 'analyzed', 'pending', 'stranded', 'last_fetched'],
            $rows,
        );
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function repair(Collection $users): void
    {
        $requeued = 0;

        foreach ($users as $user) {
            $connection = $user->stravaConnection;
            if ($connection === null || $connection->isRevoked()) {
                continue;
            }

            $stranded = $this->strandedQuery($user->id)->orderBy('id')->pluck('id');
            foreach ($stranded as $activityId) {
                IngestActivityJob::dispatch((int) $activityId);
                $requeued++;
            }

            if ($stranded->isNotEmpty()) {
                $this->line("user {$user->id}: re-dispatched {$stranded->count()} stranded activities");
            }
        }

        $this->info($requeued === 0 ? 'Nothing to repair.' : "Re-dispatched {$requeued} activities for ingestion.");
    }

    private function reportWebhook(): void
    {
        $callbackUrl = route('strava.webhook.verify');
        $verifyToken = (string) config('services.strava.webhook_verify_token');

        $this->line("Webhook callback: {$callbackUrl}");

        if ($verifyToken === '') {
            $this->warn('Webhook self-handshake: SKIPPED (STRAVA_WEBHOOK_VERIFY_TOKEN not configured).');
        } elseif (app(StravaWebhookProbe::class)->probe($callbackUrl, $verifyToken)['passed']) {
            $this->info('Webhook self-handshake: PASS (origin echoed the challenge). Probes from a trusted IP, so it does not prove Strava reaches the edge: if a subscription still fails with "GET to callback URL does not return 200", check Cloudflare Bot Fight Mode.');
        } else {
            $this->error('Webhook self-handshake: FAIL. Strava would reject a subscription. Check the app container env (verify token) and Cloudflare access to /strava/webhook.');
        }

        $this->line('Subscription listing: php artisan strava:webhook-subscribe --action=view');
    }

    private function connectionLabel(User $user): string
    {
        $connection = $user->stravaConnection;
        if ($connection === null) {
            return 'none';
        }
        if ($connection->isRevoked()) {
            return 'revoked';
        }
        if ($connection->token_expires_at->isPast()) {
            return 'token-expired';
        }

        return 'ok';
    }

    /**
     * @return array{total: int, analyzed: int, pending: int, stranded: int}
     */
    private function activityCounts(int $userId): array
    {
        $base = Activity::query()->withStubs()->where('user_id', $userId);

        return [
            'total' => (clone $base)->count(),
            'analyzed' => (clone $base)->whereNotNull('analyzed_at')->count(),
            'pending' => (clone $base)->whereNull('analyzed_at')->count(),
            'stranded' => $this->strandedQuery($userId)->count(),
        ];
    }

    /**
     * Stranded = never analyzed and still within the retry budget, so a re-run
     * has a real chance of completing (a row at the cap is treated as handled).
     *
     * @return Builder<Activity>
     */
    private function strandedQuery(int $userId): Builder
    {
        return Activity::query()
            ->pendingIngest()
            ->where('user_id', $userId);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(): Collection
    {
        return User::query()
            ->whereHas('stravaConnection')
            ->with('stravaConnection')
            ->when($this->option('user'), fn ($query, $userId) => $query->whereKey($userId))
            ->get();
    }
}
