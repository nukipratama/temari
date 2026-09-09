<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleHeartbeatCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('structure');

/**
 * The scheduled event for exactly this artisan command.
 *
 * Matched on a word boundary rather than a substring: `strava:sync` is a prefix of
 * `strava:sync-zones`, which registers first, so a `str_contains` lookup silently
 * asserted against the monthly zone sync instead of the fallback poll.
 */
function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())->first(
        fn (Event $e): bool => preg_match('/\\b'.preg_quote($command, '/').'(?:\\s|$)/', (string) $e->command) === 1,
    );
}

/**
 * The Strava drains carry a bounded withoutOverlapping expiry so a stranded lock
 * self-releases instead of holding the default 24h. The bound tracks each command's
 * own cadence: the expiry only matters when a run strands (a completed run releases
 * its lock), so it is sized to clear within roughly one tick rather than parking the
 * drain for the rest of the hour.
 */
it('bounds the overlap expiry on the Strava drains', function (string $command, int $expiry): void {
    $event = scheduledEvent($command);

    expect($event)->not->toBeNull("scheduled command [{$command}] is not registered")
        ->and($event->withoutOverlapping)->toBeTrue("[{$command}] must use withoutOverlapping")
        ->and($event->expiresAt)->toBe($expiry, "[{$command}] overlap expiry must be bounded");
})->with([
    'strava:ingest every 5 min' => ['strava:ingest', 10],
    'strava:sync hourly' => ['strava:sync', 55],
    'strava:hydrate-backlog every 15 min' => ['strava:hydrate-backlog', 14],
]);

/**
 * failed_jobs is never pruned by default and bloats a constrained host, reading
 * as an alarming count during triage though most rows are superseded dupes of
 * the same Analysis rows. Guards that the retention sweep stays scheduled.
 */
it('schedules the failed_jobs retention prune', function (): void {
    $event = scheduledEvent('queue:prune-failed');

    expect($event)->not->toBeNull('queue:prune-failed is not scheduled')
        ->and($event->command)->toContain('--hours=168');
});

/**
 * ai_token_usages and strava_sync_logs had no retention at all before this.
 * Guards that the sweep stays scheduled.
 */
it('schedules the analytics-connection retention prune', function (): void {
    $event = scheduledEvent('analytics:prune');

    expect($event)->not->toBeNull('analytics:prune is not scheduled');
});

/**
 * The scheduler container's healthcheck asserts the heartbeat is younger than
 * ScheduleHeartbeatCommand::STALE_AFTER_SECONDS, so the beat must stay on an
 * every-minute cadence and must not carry an overlap lock (the scheduler mutex
 * lives on the evictable cache store). Drop either and the healthcheck flaps.
 */
it('schedules the liveness heartbeat every minute without an overlap lock', function (): void {
    $event = scheduledEvent('schedule:heartbeat');

    expect($event)->not->toBeNull('schedule:heartbeat must be scheduled')
        ->and($event->expression)->toBe('* * * * *', 'schedule:heartbeat must run every minute')
        ->and($event->withoutOverlapping)->toBeFalse('schedule:heartbeat must not take the cache-backed scheduler mutex')
        ->and(ScheduleHeartbeatCommand::STALE_AFTER_SECONDS)->toBeGreaterThan(60, 'the staleness window needs headroom over the one-minute cadence');
});

/**
 * weather:backfill is the documented weather self-repair path: without it, a run
 * ingested during an Open-Meteo blip keeps null weather forever. Guard that it is
 * actually scheduled (daily) with a bounded overlap lock.
 */
it('schedules the weather:backfill self-repair sweep with a bounded overlap', function (): void {
    $event = scheduledEvent('weather:backfill');

    expect($event)->not->toBeNull('weather:backfill must be scheduled')
        ->and($event->withoutOverlapping)->toBeTrue('weather:backfill must use withoutOverlapping')
        ->and($event->expiresAt)->toBe(55, 'weather:backfill overlap expiry must be bounded');
});

/**
 * The ingest cadences are load-bearing rather than incidental: the drain is sized
 * against the 15-minute read bucket it spends from, and the fallback poll is the
 * one scheduled job whose cost scales with connected users. Both were retuned once
 * it was clear how much of the shared Strava pool actually sits idle, so pin them.
 */
it('runs the Strava drain and fallback poll on the cadences they were sized for', function (string $command, string $expression): void {
    $event = scheduledEvent($command);

    expect($event)->not->toBeNull("scheduled command [{$command}] is not registered")
        ->and($event->expression)->toBe($expression);
})->with([
    // Matches the read bucket's own decay window, so recovered headroom is never
    // left idle for most of an hour.
    'hydrate-backlog tracks the 15-minute bucket' => ['strava:hydrate-backlog', '*/15 * * * *'],
    // Around the clock: the old 04-10/16-22 window left a five-hour overnight gap
    // in which a missed webhook went unnoticed.
    'sync polls hourly with no overnight gap' => ['strava:sync', '0 * * * *'],
]);

/**
 * Exactly one scheduler container runs in prod (compose.prod.yaml), an
 * unstated invariant a second replica would silently break: nothing today
 * stops two schedulers from double-running a command or racing a shared
 * table. `withoutOverlapping()` (single-container overlap safety) and
 * `onOneServer()` (multi-container safety, free today, load-bearing if the
 * container ever scales past one) are cheap enough to hold on every event.
 * `schedule:heartbeat` is the one deliberate exception to the overlap lock
 * (see its own comment in routes/console.php) but still takes onOneServer.
 */
it('makes every scheduled event overlap-safe and single-host', function (): void {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    $events->each(function (Event $event): void {
        expect($event->onOneServer)->toBeTrue("[{$event->command}] must use onOneServer");

        if (str_contains((string) $event->command, 'schedule:heartbeat')) {
            expect($event->withoutOverlapping)->toBeFalse('schedule:heartbeat must not take the cache-backed scheduler mutex');

            return;
        }

        expect($event->withoutOverlapping)->toBeTrue("[{$event->command}] must use withoutOverlapping");
    });
});
