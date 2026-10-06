<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Enums\SessionType;
use App\Jobs\Notifications\RetryStaleWebPushNotificationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\HeldNotification;
use App\Models\InboxNotification;
use App\Models\NotificationDelivery;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\DayClampedNotification;
use App\Notifications\FitnessImprovedNotification;
use App\Notifications\MorningBriefingNotification;
use App\Notifications\RaceOutcomeNotification;
use App\Notifications\RaceTomorrowNotification;
use App\Notifications\StravaDisconnectedNotification;
use App\Notifications\StreakReminderNotification;
use App\Notifications\TestNotification;
use App\Notifications\TimeTrialNotification;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    Carbon::setTestNow('2026-10-05 23:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function quietAthlete(bool $demo = false): User
{
    $user = User::factory()->create(['is_demo' => $demo]);
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'revoked_at' => null]);
    $user->updatePushSubscription('https://push.example/endpoint', str_repeat('a', 87), str_repeat('b', 22));

    return $user->fresh();
}

function quietPostRun(User $user, ?Carbon $startedAt = null): AnalysisReadyNotification
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startedAt ?? now()]);

    return new AnalysisReadyNotification(doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, content: 'steady one.'));
}

function quietPushCount(): int
{
    return test()->pushes;
}

function expectQuietDeliveries(int $inbox, int $telegram, int $push): void
{
    expect(InboxNotification::query()->count())->toBe($inbox)
        ->and(Http::recorded()->count())->toBe($telegram)
        ->and(quietPushCount())->toBe($push);
}

dataset('notification types', [
    'post-run story' => [fn (User $user): Notification => quietPostRun($user), 1, true],
    'day clamped' => [fn (User $user): Notification => new DayClampedNotification('2026-10-06', SessionType::Rest, 'a full rest today.'), 1, false],
    'fitness improved' => [fn (User $user): Notification => new FitnessImprovedNotification(10_000.0, 3_570, 3_640, 5_000, '2026-09-20', '2026-10-05'), 1, true],
    'morning briefing' => [fn (User $user): Notification => new MorningBriefingNotification(Analysis::factory()->done('easy 5k.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-10-06',
    ])), 0, true],
    'race outcome' => [fn (User $user): Notification => new RaceOutcomeNotification(RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-04'])), 1, true],
    'race tomorrow' => [fn (User $user): Notification => new RaceTomorrowNotification(RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-06'])), 1, true],
    'strava disconnected' => [fn (User $user): Notification => new StravaDisconnectedNotification(now()), 1, true],
    'streak reminder' => [fn (User $user): Notification => new StreakReminderNotification(4), 1, true],
    'time trial' => [fn (User $user): Notification => new TimeTrialNotification(PlannedSession::factory()->for($user)->create(['date' => '2026-10-05', 'prescription_race_context' => ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0]])), 1, true],
]);

it('holds every channel inside the window and releases each once at 04:00', function (Closure $make, int $inbox, bool $outbound, bool $demo): void {
    $user = quietAthlete($demo);
    $user->notify($make($user));

    $outboundSends = $outbound && ! $demo ? 1 : 0;
    expectQuietDeliveries(0, 0, 0);
    expect(HeldNotification::query()->count())->toBe($inbox + 2 * $outboundSends)
        ->and(NotificationDelivery::query()->count())->toBe(0);

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expectQuietDeliveries($inbox, $outboundSends, $outboundSends);
    expect(HeldNotification::query()->count())->toBe(0);
})->with('notification types')->with(['athlete' => false, 'demo' => true]);

it('sends the manual test notification at once inside the window', function (): void {
    $user = quietAthlete();

    $user->notify(new TestNotification());

    expectQuietDeliveries(1, 1, 1);
    expect(HeldNotification::query()->count())->toBe(0);
});

it('holds from 22:00:00 and stops holding at 04:00:00', function (string $at, bool $held): void {
    Carbon::setTestNow($at);
    $user = quietAthlete();

    $user->notify(new StreakReminderNotification(4));

    expect(HeldNotification::query()->count())->toBe($held ? 3 : 0)
        ->and(InboxNotification::query()->count())->toBe($held ? 0 : 1);
})->with([
    '21:59:59' => ['2026-10-05 21:59:59', false],
    '22:00:00' => ['2026-10-05 22:00:00', true],
    '03:59:59' => ['2026-10-06 03:59:59', true],
    '04:00:00' => ['2026-10-06 04:00:00', false],
]);

it('takes no delivery claim while a keyed notification is held, and recovery leaves it alone', function (): void {
    $user = quietAthlete();
    $notification = quietPostRun($user);
    $user->notify($notification);

    Carbon::setTestNow('2026-10-06 02:00:00');
    Queue::fake([RetryStaleWebPushNotificationJob::class]);
    $this->artisan('notifications:recover-deliveries')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(NotificationDelivery::query()->count())->toBe(0);

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expect(NotificationDelivery::query()->where('analysis_id', $notification->analysis->id)->pluck('status', 'channel')->all())
        ->toEqual(['telegram' => NotificationDeliveryStatus::Sent, 'webpush' => NotificationDeliveryStatus::Sent]);
});

it('never counts held time against the post-run recency gate', function (): void {
    $user = quietAthlete();
    $user->notify(quietPostRun($user, now()->subDays(3)->addHour()));

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:release-held')->assertExitCode(0);

    expectQuietDeliveries(1, 1, 1);
});

it('holds a stale web-push retry that lands in the window instead of settling it', function (): void {
    $user = quietAthlete();
    $notification = quietPostRun($user);
    $delivery = NotificationDelivery::query()->create([
        'analysis_id' => $notification->analysis->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);

    $this->artisan('notifications:recover-deliveries')->assertExitCode(0);

    expect(quietPushCount())->toBe(0)
        ->and($delivery->fresh()->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($delivery->fresh()->claim_version)->toBe(1);

    Carbon::setTestNow('2026-10-06 04:00:00');
    $this->artisan('notifications:recover-deliveries')->assertExitCode(0);

    expect(quietPushCount())->toBe(1)
        ->and($delivery->fresh()->status)->toBe(NotificationDeliveryStatus::Sent);
});
