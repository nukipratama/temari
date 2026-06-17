<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ActivityIngested;
use App\Listeners\DispatchPostRunAnalysis;
use App\Listeners\RecordScheduledTaskRun;
use App\Listeners\VerifyDependencies;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimeline;
use App\Support\Config\AppConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Override;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Strava\StravaExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(VerdictNarrator::class, VerdictTimeline::class);

        // Scoped: one shared instance per request/command (so `withoutDispatching()`
        // reaches collaborators), flushed by Octane between requests.
        $this->app->scoped(AnalysisService::class);

        // Scoped so its per-request/per-job read memo collapses repeat lookups but
        // stays fresh across requests and queue jobs (DB remains source of truth).
        $this->app->scoped(AppConfig::class);
    }

    public function boot(): void
    {
        // The analytics-schema migrations live outside the default path (they
        // run via `--path` against the `analytics` connection in dev/prod). In
        // testing the analytics connection shares the default test DB, so load
        // them into the normal migrate run that RefreshDatabase performs.
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/analytics'));
        }

        Event::listen(SocialiteWasCalled::class, StravaExtendSocialite::class);

        // Deepen the `/up` health route to fail when MySQL or Redis is unreachable.
        Event::listen(DiagnosingHealth::class, VerifyDependencies::class);

        // Post-ingest AI analysis fan-out runs in its own queued job.
        Event::listen(ActivityIngested::class, DispatchPostRunAnalysis::class);

        // Scheduler heartbeat: record every command's last run for the Pulse card.
        Event::listen(ScheduledTaskFinished::class, [RecordScheduledTaskRun::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [RecordScheduledTaskRun::class, 'failed']);

        // Defense in depth: edge basicauth (docker/Caddyfile) is the first gate,
        // this app-layer allow-list is the second so a Caddy misconfig can't expose
        // the dashboards. Local dev always passes; in prod only the seeded demo user
        // or a configured ops email does. The `?Authenticatable` param keeps the
        // gate callable for guests (it returns false for them outside local).
        Gate::define('viewPulse', fn (?Authenticatable $user = null): bool => self::isOpsUser($user));
        Gate::define('viewAiUsage', fn (?Authenticatable $user = null): bool => self::isOpsUser($user));

        RateLimiter::for('analysis-trigger', function (Request $request): Limit {
            $perMinute = max(1, (int) config('ai.rate_limit_per_minute', 8));
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        // "Sync now" button. The orchestrator lock already de-dupes overlapping
        // syncs; this just keeps an impatient tapper from flooding the queue.
        RateLimiter::for('strava-sync', function (Request $request): Limit {
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute(2)->by($key);
        });

        // Client-error telemetry sink. IP-keyed so a single misbehaving browser
        // (error loop) can't flood the logs.
        RateLimiter::for('client-errors', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
    }

    /**
     * Allow-list predicate shared by the Horizon / Pulse / ai-usage gates. Local
     * dev always passes. In other environments the app-layer gate only engages
     * once `app.ops_emails` (env-driven) is configured: until then it stays open
     * so a deploy can never lock the owner out, leaving the edge basicauth as the
     * gate. Once configured, only those emails pass, and demo accounts are
     * excluded because demo login is public in production.
     */
    public static function isOpsUser(?Authenticatable $user): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        /** @var list<string> $opsEmails */
        $opsEmails = (array) config('app.ops_emails', []);

        if ($opsEmails === []) {
            return true;
        }

        return $user instanceof User
            && ! $user->is_demo
            && in_array($user->email, $opsEmails, true);
    }
}
