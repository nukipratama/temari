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
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\RaceTomorrowNotification;
use App\Notifications\StravaDisconnectedNotification;
use App\Notifications\StreakReminderNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    expect(sentTelegramTitles())->toBe(['Your 4-week streak is on the edge', 'Strava stopped syncing', 'Race day is tomorrow'])
        ->and(InboxNotification::query()->orderBy('id')->pluck('title')->all())
        ->toBe(['Your 4-week streak is on the edge', 'Strava stopped syncing', 'Race day is tomorrow'])
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

    expect(sentTelegramTitles())->toBe(['Race day is tomorrow'])
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

    expect(sentTelegramTitles())->toBe(['Your briefing for today'])
        ->and($this->pushes)->toBe(1);
});

it('drops a held row that can never be restored, releases the rest and fails the run', function (Closure $poison, string $storedClass): void {
    Log::spy();
    $user = heldAthlete();
    holdAt('2026-10-05 22:30:00', $user, fn () => new StreakReminderNotification(4));
    holdAt('2026-10-05 23:00:00', $user, fn () => new StravaDisconnectedNotification(now()));
    $poisoned = HeldNotification::query()->where('held_at', '2026-10-05 22:30:00')->get();
    $poisoned->each(fn (HeldNotification $held) => $held->update(['notification' => $poison($held->notification)]));

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 3 held notifications.')
        ->assertExitCode(1);

    expect(sentTelegramTitles())->toBe(['Strava stopped syncing'])
        ->and(InboxNotification::query()->pluck('title')->all())->toBe(['Strava stopped syncing'])
        ->and(HeldNotification::query()->count())->toBe(0);
    foreach ($poisoned as $held) {
        Log::shouldHaveReceived('error')->with('notifications.held.unrestorable', ['held_id' => $held->id, 'class' => $storedClass]);
    }
})->with([
    'renamed class' => [
        fn (string $payload): string => str_replace('O:44:"App\Notifications\StreakReminderNotification"', 'O:37:"App\Notifications\RetiredNotification"', $payload),
        'App\Notifications\RetiredNotification',
    ],
    'not a notification' => [fn (string $payload): string => serialize(['streak' => 4]), 'array'],
    'truncated' => [fn (string $payload): string => substr($payload, 0, 80), StreakReminderNotification::class],
]);

it('keeps a held row whose release fails for another reason, releases the rest and fails the run', function (): void {
    Log::spy();
    $sends = 0;
    $push = Mockery::mock(WebPushChannel::class);
    $push->shouldReceive('send')->andReturnUsing(function () use (&$sends): array {
        if ($sends++ === 0) {
            throw new RuntimeException('push service down');
        }
        $this->pushes++;

        return [];
    });
    app()->instance(WebPushChannel::class, $push);
    $user = heldAthlete();
    holdAt('2026-10-05 22:30:00', $user, fn () => new StreakReminderNotification(4));
    holdAt('2026-10-05 23:00:00', $user, fn () => new StravaDisconnectedNotification(now()));

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')
        ->expectsOutput('Released 5 held notifications.')
        ->assertExitCode(1);

    $kept = HeldNotification::query()->sole();
    expect($kept->channel)->toBe(IdempotentWebPushChannel::class)
        ->and($kept->held_at->toDateTimeString())->toBe('2026-10-05 22:30:00')
        ->and($this->pushes)->toBe(1)
        ->and(sentTelegramTitles())->toBe(['Your 4-week streak is on the edge', 'Strava stopped syncing']);
    Log::shouldHaveReceived('error')->with('notifications.held.release_failed', Mockery::on(fn (array $context): bool => $context['held_id'] === $kept->id));
});
