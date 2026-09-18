<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\NotificationFake;

uses(RefreshDatabase::class);

// Freeze today so the bare seed this file lays underneath itself matches
// DemoSeedCommandTest.php's clock exactly.
beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * Every distinct channel a notification resolved to during the run.
 *
 * Duplicated from DemoSeedCommandTest.php and renamed (EdgeStates suffix):
 * Pest/ParaTest can batch multiple test files into the same worker process,
 * and two top-level functions with the same name in that process is a fatal
 * "Cannot redeclare function" — no test file in this suite currently shares a
 * helper name with another, so this file must not be the first to.
 *
 * @return list<string>
 */
function channelsUsedByEdgeStates(NotificationFake $notifications): array
{
    $channels = [];

    foreach ($notifications->sentNotifications() as $byKey) {
        foreach ($byKey as $byNotification) {
            foreach ($byNotification as $records) {
                foreach ($records as $record) {
                    $channels = [...$channels, ...$record['channels']];
                }
            }
        }
    }

    sort($channels);

    return array_values(array_unique($channels));
}

/**
 * Commits whatever the current test just seeded for real — outside the
 * per-test transaction RefreshDatabase wraps every test in — then reopens
 * that transaction so the running test's own further writes still roll back
 * normally at teardown. This is what lets the fixture below outlive the one
 * test that seeds it.
 */
function commitEdgeStatesFixture(): void
{
    DB::connection('mysql')->commit();
    DB::connection('analytics')->commit();
    DB::connection('mysql')->beginTransaction();
    DB::connection('analytics')->beginTransaction();
}

/**
 * Seeds the bare demo dataset this file needs underneath --with-edge-states,
 * exactly once per test process. Duplicated from DemoSeedCommandTest.php's
 * ensureBareDemoSeeded() rather than shared, so this file's own edge-state
 * fixture never depends on load order relative to that file — each of the
 * two files' processes now pays its own bare-seed cost.
 */
function ensureBareDemoSeededForEdgeStates(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    config()->set('services.telegram.bot_token', 'test-token');
    Queue::fake();
    $notifications = Notification::fake();

    $exitCode = Artisan::call('demo:seed');
    expect($exitCode)->toBe(0);
    expect(channelsUsedByEdgeStates($notifications))->toBe([]);

    commitEdgeStatesFixture();

    $done = true;
}

/**
 * Layers --with-edge-states on top of this file's own bare fixture, exactly
 * once per test process, for the same reason and by the same mechanism as
 * ensureBareDemoSeededForEdgeStates().
 */
function ensureEdgeStatesSeeded(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    ensureBareDemoSeededForEdgeStates();

    $exitCode = Artisan::call('demo:seed', ['--with-edge-states' => true]);
    expect($exitCode)->toBe(0);

    commitEdgeStatesFixture();

    $done = true;
}

/**
 * Commits the shared fixture connections one last time *without* reopening a
 * transaction, called at the end of the last test in this file. RefreshDatabase's
 * own teardown then finds each connection's PDO not mid-transaction and, per
 * its own beginDatabaseTransaction() callback, flips RefreshDatabaseState::$migrated
 * back to false — which makes the next RefreshDatabase test in this process
 * (whichever file that belongs to) run a fresh migrate:fresh before it does
 * anything else. That's Laravel's own schema-reset path, reused here instead
 * of hand-rolling a second one, so nothing this file committed for real
 * outlives it.
 */
function releaseEdgeStatesFixture(): void
{
    DB::connection('mysql')->commit();
    DB::connection('analytics')->commit();
}

/**
 * Backstop for releaseEdgeStatesFixture(): PHPUnit always calls afterAll()
 * once, after the last test of this file that actually ran — even when that
 * is not the test below (an earlier test fails before reaching it, or a
 * `--filter`/TIA-narrowed run never selects it at all). Flipping the flag
 * directly needs no live Application, unlike a throwaway-Application
 * `migrate:fresh`: it is the same plain static property RefreshDatabase's own
 * teardown already reads before every test, so whichever RefreshDatabase test
 * this process runs next always re-migrates instead of trusting whatever this
 * file left committed.
 */
afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('seeds the pending, processing and failed states the audits cannot otherwise reach', function (): void {
    ensureEdgeStatesSeeded();

    $statuses = Analysis::query()
        ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Processing, AnalysisStatus::Failed])
        ->pluck('status')
        ->map(fn (AnalysisStatus $s): string => $s->value)
        ->sort()
        ->values()
        ->all();

    expect($statuses)->toBe(['failed', 'pending', 'processing']);

    // A failed row has to carry what the dead-letter UI reads, or the state
    // renders as merely empty rather than as failed.
    $failed = Analysis::query()->where('status', AnalysisStatus::Failed)->sole();
    expect($failed->error)->not->toBeNull()
        ->and($failed->attempts)->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS)
        ->and($failed->content)->toBeNull();
});

it('applies the edge states at most once across re-runs', function (): void {
    ensureEdgeStatesSeeded();
    $first = Analysis::query()->where('status', '!=', AnalysisStatus::Done)->count();

    $this->artisan('demo:seed', ['--with-edge-states' => true])->assertSuccessful();

    expect(Analysis::query()->where('status', '!=', AnalysisStatus::Done)->count())->toBe($first);
});

it('clears the producer on a row whose content the edge states blank', function (): void {
    try {
        ensureEdgeStatesSeeded();

        expect(Analysis::query()->where('status', '!=', AnalysisStatus::Done)->whereNotNull('served_by')->count())
            ->toBe(0);
    } finally {
        releaseEdgeStatesFixture();
    }
});
