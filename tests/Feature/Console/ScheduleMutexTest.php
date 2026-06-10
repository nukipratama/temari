<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('structure');

/**
 * The Strava drains carry a BOUNDED withoutOverlapping expiry so a lock stranded
 * by a mid-run container restart self-releases instead of dead-locking for the
 * 24h default (a strand once silently halted ingest in prod). Guards against a
 * regression back to the unbounded `->withoutOverlapping()`.
 */
it('bounds the overlap expiry on the Strava drains', function (string $command, int $expiry): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull("scheduled command [{$command}] is not registered")
        ->and($event->withoutOverlapping)->toBeTrue("[{$command}] must use withoutOverlapping")
        ->and($event->expiresAt)->toBe($expiry, "[{$command}] overlap expiry must be bounded");
})->with([
    'strava:ingest every 5 min' => ['strava:ingest', 10],
    'strava:sync hourly' => ['strava:sync', 55],
]);
