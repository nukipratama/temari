<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StravaConnection;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    return app(Schedule::class)->events();
}

it('keeps only the scheduler heartbeat running in maintenance', function (): void {
    $exempt = collect(scheduledEvents())
        ->filter(fn (Event $event): bool => $event->runsInMaintenanceMode())
        ->map(fn (Event $event): string => (string) $event->command)
        ->values();

    expect($exempt)->toHaveCount(1)
        ->and($exempt->first())->toContain('schedule:heartbeat');
});

it('skips every other scheduled task while maintenance is on, and runs it once it lifts', function (): void {
    foreach (scheduledEvents() as $event) {
        $timezone = $event->timezone ?? config('app.timezone');
        Carbon::setTestNow(Carbon::instance(
            new CronExpression($event->expression)->getNextRunDate(Carbon::now($timezone), 0, false, $timezone)
        ));

        app()->maintenanceMode()->activate([]);
        expect($event->isDue(app()))->toBe($event->runsInMaintenanceMode(), (string) $event->command);

        app()->maintenanceMode()->deactivate();
        expect($event->isDue(app()))->toBeTrue((string) $event->command);
    }
});

it('never lets a Horizon supervisor force its workers through maintenance', function (): void {
    $supervisors = [
        ...array_values(config('horizon.defaults')),
        ...array_merge(...array_map(array_values(...), array_values(config('horizon.environments')))),
    ];

    foreach ($supervisors as $supervisor) {
        expect($supervisor['force'] ?? false)->toBeFalse();
    }
});

it('queues a Strava webhook event during maintenance and processes it only once maintenance lifts', function (): void {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    Http::fake(['*/activities/*' => Http::response(['message' => 'Record Not Found'], 404)]);

    $connection = StravaConnection::factory()->create(['strava_athlete_id' => 555]);
    Activity::factory()->stub()->create(['user_id' => $connection->user_id, 'strava_external_id' => 9001]);

    app()->maintenanceMode()->activate([]);

    $this->postJson(route('strava.webhook.handle'), [
        'object_type' => 'activity',
        'aspect_type' => 'delete',
        'object_id' => 9001,
        'owner_id' => 555,
    ])->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);

    // A real worker: while maintenance is on it pauses instead of popping.
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 2048])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(Activity::query()->withStubs()->where('strava_external_id', 9001)->exists())->toBeTrue();

    $this->artisan('up')->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 2048])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(Activity::query()->withStubs()->where('strava_external_id', 9001)->exists())->toBeFalse();
});
