<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('structure');

/**
 * The Strava drains carry a bounded withoutOverlapping expiry so a stranded lock
 * self-releases instead of holding the default 24h.
 */
it('bounds the overlap expiry on the Strava drains', function (string $command, int $expiry): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull("scheduled command [{$command}] is not registered")
        ->and($event->withoutOverlapping)->toBeTrue("[{$command}] must use withoutOverlapping")
        ->and($event->expiresAt)->toBe($expiry, "[{$command}] overlap expiry must be bounded");
})->with([
    'strava:ingest every 5 min' => ['strava:ingest', 10],
    'strava:sync running-hours' => ['strava:sync', 55],
]);

/**
 * failed_jobs is never pruned by default and bloats a constrained host, reading
 * as an alarming count during triage though most rows are superseded dupes of
 * the same Analysis rows. Guards that the retention sweep stays scheduled.
 */
it('schedules the failed_jobs retention prune', function (): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, 'queue:prune-failed'));

    expect($event)->not->toBeNull('queue:prune-failed is not scheduled')
        ->and($event->command)->toContain('--hours=168');
});

/**
 * weather:backfill is the documented weather self-repair path: without it, a run
 * ingested during an Open-Meteo blip keeps null weather forever. Guard that it is
 * actually scheduled (daily) with a bounded overlap lock.
 */
it('schedules the weather:backfill self-repair sweep with a bounded overlap', function (): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, 'weather:backfill'));

    expect($event)->not->toBeNull('weather:backfill must be scheduled')
        ->and($event->withoutOverlapping)->toBeTrue('weather:backfill must use withoutOverlapping')
        ->and($event->expiresAt)->toBe(55, 'weather:backfill overlap expiry must be bounded');
});
