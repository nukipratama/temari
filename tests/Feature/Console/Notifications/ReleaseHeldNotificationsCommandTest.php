<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\HeldNotification;
use App\Models\InboxNotification;
use App\Models\NotificationPreference;
use App\Models\RaceGoal;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\RaceTomorrowNotification;
use App\Notifications\StravaDisconnectedNotification;
use App\Notifications\StreakReminderNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'notifications.hold_during_quiet_hours' => true,
        'services.telegram.bot_token' => 'test-bot-token',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $this->pushes = 0;
    $push = Mockery::mock(WebPushChannel::class);
    $push->shouldReceive('send')->andReturnUsing(function (): array {
        $this->pushes++;

        return [];
    });
    app()->instance(WebPushChannel::class, $push);
});

afterEach(fn () => Carbon::setTestNow());

function heldAthlete(): User
{
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', str_repeat('a', 87), str_repeat('b', 22));

    return $user->fresh();
}

function holdAt(string $at, User $user, Closure $make): void
{
    Carbon::setTestNow($at);
    $user->notify($make());
}

/** @return list<string> */
function sentTelegramTitles(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => strtok((string) $pair[0]['text'], "\n"))
        ->values()
        ->all();
}

it('releases every held item once, in the order it was triggered', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 22:30:00', $user, fn () => new StreakReminderNotification(4));
    holdAt('2026-10-05 23:00:00', $user, fn () => new StravaDisconnectedNotification(now()));
    holdAt('2026-10-06 01:00:00', $user, fn () => new RaceTomorrowNotification(RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-07', 'name' => null])));

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 9 held notifications.')
        ->assertExitCode(0);

    expect(sentTelegramTitles())->toBe(['Your 4-week streak is on the edge', 'Strava stopped syncing', 'race day is tomorrow'])
        ->and(InboxNotification::query()->orderBy('id')->pluck('title')->all())
        ->toBe(['Your 4-week streak is on the edge', 'Strava stopped syncing', 'race day is tomorrow'])
        ->and($this->pushes)->toBe(3)
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('delivers nothing twice when the release runs again', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 0 held notifications.')
        ->assertExitCode(0);

    expect(Http::recorded()->count())->toBe(1)
        ->and($this->pushes)->toBe(1)
        ->and(InboxNotification::query()->count())->toBe(1);
});

it('writes one inbox row when a release is replayed after the row was already sent', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));
    $inbox = HeldNotification::query()->orderBy('id')->firstOrFail()->replicate();

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);
    $inbox->save();
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expect(InboxNotification::query()->count())->toBe(1);
});

it('catches up on the first run after a missed 04:00 tick', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));

    Carbon::setTestNow('2026-10-06 06:35:00');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 3 held notifications.')
        ->assertExitCode(0);

    expect(sentTelegramTitles())->toBe(['Your 4-week streak is on the edge'])
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('releases nothing while the window is still open', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));

    Carbon::setTestNow('2026-10-06 03:59:59');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Quiet hours: nothing released.')
        ->assertExitCode(0);

    expect(HeldNotification::query()->count())->toBe(3)
        ->and(Http::recorded()->count())->toBe(0);
});

it('respects a mute the athlete set while the item was held', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));
    NotificationPreference::factory()->for($user)->create(['push_enabled' => false]);

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expect($this->pushes)->toBe(0)
        ->and(Http::recorded()->count())->toBe(1)
        ->and(InboxNotification::query()->count())->toBe(1)
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('drops an item whose subject is gone and releases the rest', function (): void {
    $user = heldAthlete();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-06']);
    holdAt('2026-10-05 22:30:00', $user, fn () => new RaceTomorrowNotification($race));
    holdAt('2026-10-05 23:00:00', $user, fn () => new StreakReminderNotification(4));
    $race->delete();

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 3 held notifications.')
        ->assertExitCode(0);

    expect(sentTelegramTitles())->toBe(['Your 4-week streak is on the edge'])
        ->and(HeldNotification::query()->count())->toBe(0);
});

it('judges a released race reminder as of when it was held, not when it was released', function (): void {
    $user = heldAthlete();
    holdAt('2026-10-06 23:00:00', $user, fn () => new RaceTomorrowNotification(RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-07', 'name' => null])));

    Carbon::setTestNow('2026-10-07 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expect(sentTelegramTitles())->toBe(['race day is tomorrow'])
        ->and($this->pushes)->toBe(1);
});

it('judges a released briefing as of when it was held, not when it was released', function (): void {
    $user = heldAthlete();
    foreach (range(1, 5) as $day) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => "2026-10-0{$day} 00:00:00"]);
    }
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-10-06',
        'status' => AnalysisStatus::Done,
        'content' => 'a midnight runner.',
    ]);
    Carbon::setTestNow('2026-10-06 00:15:00');
    $this->artisan('briefing:morning-push')->assertExitCode(0);

    expect(HeldNotification::query()->count())->toBe(2);

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expect(sentTelegramTitles())->toBe(['your briefing for today'])
        ->and($this->pushes)->toBe(1);
});
