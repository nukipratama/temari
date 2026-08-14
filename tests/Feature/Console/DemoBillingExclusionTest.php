<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('structure');

/**
 * The demo account is excluded from every kickoff billing scheduler, with no
 * exception. See docs/decisions/demo-user-billing-exclusion.md.
 *
 * Exclusion is applied per command, not globally, so a newly scheduled command
 * is included by default and nothing notices. This is the tripwire: every
 * scheduled command has to be classified below, and one that is neither known
 * to exclude the demo user nor listed as unable to bill fails the gate.
 *
 * The behavioural proof for each entry in BILLING lives with the command's own
 * test (see the "skips/excludes the demo user" cases in tests/Feature/Console).
 */

/**
 * Spends money or Strava budget per user. Must filter the demo account out of
 * its own user selection.
 *
 * @var array<string, string>
 */
const BILLING = [
    'ai:daily-briefing' => 'User::notDemo() on the active-user scan',
    'ai:weekly-recap' => 'User::notDemo() on the recap scan',
    'ai:weekly-profile' => 'User::notDemo() on the profile scan',
    'ai:monthly-recap' => 'User::notDemo() on the user list',
    'strava:sync' => 'notDemo() on the connection scan',
    'strava:sync-zones' => 'notDemo() on the connection scan',
    'strava:ingest' => 'whereHas(user, is_demo = false) on the stub drain',
    'streak:remind' => 'where(is_demo, false) inside the command',
];

/**
 * Cannot bill on the demo account's behalf, with the reason it cannot. A blanket
 * notDemo() here would be cargo cult, so each one states why it is safe.
 *
 * @var array<string, string>
 */
const NON_BILLING = [
    'schedule:heartbeat' => 'writes one Redis timestamp, touches no user',
    'demo:daily-refresh' => 'the demo account is the point; rule-based fill, zero LLM tokens',
    'plan:regenerate' => 'deterministic periodizer, no LLM and no Strava call',
    'ai:self-heal' => 'only re-kicks Pending rows; demo rows are seeded Done, and the sweeps that could bill apply notDemo() themselves',
    'queue:prune-failed' => 'deletes rows, touches no user',
    'geo:backfill-locations' => 'free Nominatim lookup, no LLM and no Strava call',
    'weather:correct-forecast' => 'free Open-Meteo lookup, no LLM and no Strava call',
    'weather:backfill' => 'free Open-Meteo lookup, no LLM and no Strava call',
    'streak:settle' => 'reads weekly snapshots and writes rest-token rows, no LLM and no Strava call',
];

/**
 * @return list<string>
 */
function scheduledCommandNames(): array
{
    return collect(app(Schedule::class)->events())
        ->map(function (Event $event): ?string {
            preg_match('/artisan[\'"]? (?:[\'"])?([a-z0-9:_-]+)/i', (string) $event->command, $matches);

            return $matches[1] ?? null;
        })
        ->filter()
        ->unique()
        ->values()
        ->all();
}

it('classifies every scheduled command as billing or not, so a new one cannot slip past the demo exclusion', function (): void {
    $classified = array_merge(array_keys(BILLING), array_keys(NON_BILLING));
    $unclassified = array_diff(scheduledCommandNames(), $classified);

    expect($unclassified)->toBe(
        [],
        'A newly scheduled command is not classified. If it bills per user (LLM or Strava reads), it must exclude the demo account and be listed in BILLING; otherwise list it in NON_BILLING with the reason it cannot bill. See docs/decisions/demo-user-billing-exclusion.md.',
    );
});

it('keeps every command it calls billing actually scheduled', function (): void {
    $scheduled = scheduledCommandNames();

    foreach (array_keys(BILLING) as $command) {
        expect(in_array($command, $scheduled, true))
            ->toBeTrue("[{$command}] is listed as a billing scheduler but is no longer scheduled");
    }
});

it('reads the demo exclusion straight out of each billing command source', function (string $command, string $path): void {
    $source = file_get_contents(base_path($path));

    expect($source)->toBeString()
        ->and($source)->toMatch('/notDemo\(\)|is_demo.{0,20}false/s', "[{$command}] no longer filters the demo account out of its user selection");
})->with([
    'ai:daily-briefing' => ['ai:daily-briefing', 'app/Console/Commands/AI/DailyBriefingCommand.php'],
    'ai:weekly-recap' => ['ai:weekly-recap', 'app/Console/Commands/AI/WeeklyRecapCommand.php'],
    'ai:weekly-profile' => ['ai:weekly-profile', 'app/Console/Commands/AI/WeeklyProfileCommand.php'],
    'ai:monthly-recap' => ['ai:monthly-recap', 'app/Console/Commands/AI/MonthlyRecapCommand.php'],
    'strava:sync' => ['strava:sync', 'app/Console/Commands/Strava/SyncCommand.php'],
    'strava:sync-zones' => ['strava:sync-zones', 'app/Console/Commands/Strava/SyncZonesCommand.php'],
    'strava:ingest' => ['strava:ingest', 'app/Console/Commands/Strava/IngestCommand.php'],
    'streak:remind' => ['streak:remind', 'app/Console/Commands/Gamification/StreakRemindCommand.php'],
]);
