<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\HeldNotification;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PlannedSession;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-24 06:05:00'));

afterEach(fn () => Carbon::setTestNow());

/** An athlete who runs at 06:00, subscribed to push, with today's briefing narrated. */
function morningAthlete(string $usualStart = '06:00:00', ?AnalysisStatus $briefingStatus = AnalysisStatus::Done): User
{
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$user->id, 'p256dh-key', 'auth-token');

    foreach (range(1, 5) as $day) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::parse('2026-05-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' '.$usualStart),
        ]);
    }

    if ($briefingStatus instanceof AnalysisStatus) {
        Analysis::factory()->create([
            'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::BriefingMascotVoice,
            'discriminator' => '2026-05-24',
            'status' => $briefingStatus,
            'content' => 'easy 5k, nothing clever.',
        ]);
    }

    return $user;
}

it('pushes to an athlete whose usual start falls in this quarter hour or twenty minutes before the tick', function (string $now): void {
    Notification::fake();
    Carbon::setTestNow($now);

    $user = morningAthlete();

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 1 athletes.')
        ->assertSuccessful();

    Notification::assertSentTo($user, MorningBriefingNotification::class);
})->with([
    'the same quarter hour' => '2026-05-24 06:05:00',
    'twenty minutes after the bucket' => '2026-05-24 06:20:00',
]);

it('leaves an athlete alone on a day they skipped, and still pushes on a later skipped day', function (): void {
    Notification::fake();

    $skippedToday = morningAthlete();
    PlannedSession::factory()->for($skippedToday)->create(['date' => '2026-05-24', 'skipped' => true]);
    $skippedTomorrow = morningAthlete();
    PlannedSession::factory()->for($skippedTomorrow)->create(['date' => '2026-05-25', 'skipped' => true]);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 1 athletes.')
        ->assertSuccessful();

    Notification::assertNotSentTo($skippedToday, MorningBriefingNotification::class);
    Notification::assertSentTo($skippedTomorrow, MorningBriefingNotification::class);
});

it('leaves an athlete alone outside their own bucket', function (string $usualStart, string $now): void {
    Notification::fake();
    Carbon::setTestNow($now);

    morningAthlete($usualStart);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 0 athletes.')
        ->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'ninety-five minutes before' => ['04:30:00', '2026-05-24 06:05:00'],
    'the quarter hour after' => ['06:20:00', '2026-05-24 06:05:00'],
    'the evening' => ['19:00:00', '2026-05-24 06:05:00'],
    'ninety minutes after the bucket' => ['06:00:00', '2026-05-24 07:30:00'],
]);

// Fewer than five runs is the 06:00 fallback, which this bucket happens to be,
// so a new athlete is reached at a sane default rather than not at all.
it('reaches an athlete with too little history at the 06:00 fallback', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-24',
        'status' => AnalysisStatus::Done,
        'content' => 'welcome in.',
    ]);

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertSentTo($user, MorningBriefingNotification::class);
});

it('skips an athlete whose briefing is not done, and never generates one', function (?AnalysisStatus $status): void {
    Notification::fake();

    morningAthlete('06:00:00', $status);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 0 athletes.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(Analysis::query()->count())->toBe($status instanceof AnalysisStatus ? 1 : 0);
})->with([
    'no row at all' => null,
    'still pending' => AnalysisStatus::Pending,
    'failed' => AnalysisStatus::Failed,
]);

it('skips yesterday\'s briefing rather than re-pushing it', function (): void {
    Notification::fake();

    $user = morningAthlete('06:00:00', null);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-23',
        'status' => AnalysisStatus::Done,
        'content' => 'yesterday.',
    ]);

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertNothingSent();
});

// The briefing reaches Telegram as well as the lock screen, so the sweep has to
// select an athlete whose only wired channel is Telegram; a push-subscription
// scope would queue nothing for them.
it('pushes to a Telegram-only athlete in the bucket', function (): void {
    Notification::fake();
    config(['services.telegram.bot_token' => 'test-bot-token']);

    $user = morningAthlete();
    $user->pushSubscriptions()->delete();
    TelegramConnection::factory()->for($user)->create();

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 1 athletes.')
        ->assertSuccessful();

    Notification::assertSentTo($user, MorningBriefingNotification::class);
});

it('skips an athlete with no push subscription', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-24',
        'status' => AnalysisStatus::Done,
        'content' => 'nobody is listening.',
    ]);

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips an athlete who muted push', function (): void {
    Notification::fake();

    $user = morningAthlete();
    NotificationPreference::factory()->for($user)->create(['push_enabled' => false]);

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips an athlete who turned the master switch off', function (): void {
    Notification::fake();

    $user = morningAthlete();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 0 athletes.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('excludes the demo account, like every other kickoff', function (): void {
    Notification::fake();

    $user = morningAthlete();
    $user->update(['is_demo' => true]);

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertNothingSent();
});

it('pushes a briefing that turns done after its bucket on the next tick', function (): void {
    Notification::fake();

    $user = morningAthlete('06:00:00', AnalysisStatus::Pending);

    $this->artisan('briefing:morning-push')->assertSuccessful();
    Notification::assertNothingSent();

    Analysis::query()->where('subject_id', $user->id)->update(['status' => AnalysisStatus::Done]);
    Carbon::setTestNow('2026-05-24 06:20:00');

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertSentToTimes($user, MorningBriefingNotification::class, 1);
});

it('sends once per analysis and channel however many ticks fall in the window', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    $pushes = 0;
    $push = Mockery::mock(WebPushChannel::class);
    $push->shouldReceive('send')->andReturnUsing(function () use (&$pushes): array {
        $pushes++;

        return [];
    });
    app()->instance(WebPushChannel::class, $push);

    $user = morningAthlete('06:00:00', AnalysisStatus::Pending);
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'revoked_at' => null]);
    $this->artisan('briefing:morning-push')->assertSuccessful();
    Analysis::query()->where('subject_id', $user->id)->update(['status' => AnalysisStatus::Done]);

    foreach (['06:20:00', '06:35:00', '06:50:00'] as $time) {
        Carbon::setTestNow('2026-05-24 '.$time);
        $this->artisan('briefing:morning-push')->assertSuccessful();
    }

    expect(Http::recorded()->count())->toBe(1)
        ->and($pushes)->toBe(1);
});

it('sends the 00:00 bucket on a tick after the 00:01 generation', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-05-24 00:00:00');

    $user = morningAthlete('00:00:00', null);

    $this->artisan('briefing:morning-push')->assertSuccessful();
    Notification::assertNothingSent();

    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-24',
        'status' => AnalysisStatus::Done,
        'content' => 'a midnight runner.',
    ]);
    Carbon::setTestNow('2026-05-24 00:15:00');

    $this->artisan('briefing:morning-push')->assertSuccessful();

    Notification::assertSentTo($user, MorningBriefingNotification::class);
});

it('holds a quiet-hours briefing once per channel across catch-up ticks', function (): void {
    config([
        'notifications.hold_during_quiet_hours' => true,
        'services.telegram.bot_token' => 'test-bot-token',
    ]);
    Http::fake();
    Carbon::setTestNow('2026-05-24 00:05:00');
    $user = morningAthlete('00:00:00');
    TelegramConnection::factory()->for($user)->create(['chat_id' => 4242, 'revoked_at' => null]);

    $this->artisan('briefing:morning-push')->assertSuccessful();
    $held = HeldNotification::query()->pluck('id')->all();
    Carbon::setTestNow('2026-05-24 00:20:00');
    $this->artisan('briefing:morning-push')->assertSuccessful();

    expect($held)->toHaveCount(2)
        ->and(HeldNotification::query()->pluck('id')->all())->toBe($held);
    Http::assertNothingSent();
});

it('pushes only the athletes due this tick across a mixed set of slots', function (): void {
    Notification::fake();

    $due = morningAthlete('06:00:00');
    $dueNoBriefing = morningAthlete('06:00:00', null);
    $dueAlreadyClaimed = morningAthlete('06:00:00');
    $later = morningAthlete('09:00:00');
    $earlier = morningAthlete('04:00:00');
    NotificationDelivery::query()->create([
        'analysis_id' => Analysis::query()->where('subject_id', $dueAlreadyClaimed->id)->value('id'),
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Sent,
        'created_at' => now(),
        'claim_version' => 1,
    ]);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 1 athletes.')
        ->assertSuccessful();

    Notification::assertSentTo($due, MorningBriefingNotification::class);
    foreach ([$dueNoBriefing, $dueAlreadyClaimed, $later, $earlier] as $user) {
        Notification::assertNotSentTo($user, MorningBriefingNotification::class);
    }
});

it('loads briefings and deliveries once however many athletes are due', function (): void {
    Notification::fake();
    foreach (range(1, 4) as $ignored) {
        morningAthlete('06:00:00');
    }
    morningAthlete('09:00:00');

    $selects = ['ai_analyses' => 0, 'notification_deliveries' => 0];
    DB::listen(function (QueryExecuted $query) use (&$selects): void {
        foreach (array_keys($selects) as $table) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, "from `{$table}`")) {
                $selects[$table]++;
            }
        }
    });

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 4 athletes.')
        ->assertSuccessful();

    expect($selects)->toBe(['ai_analyses' => 1, 'notification_deliveries' => 1]);
});
