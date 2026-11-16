<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\IdempotentWebPushChannel;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\EasyTooHardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushMessage;

uses(RefreshDatabase::class);

const HOT_TUESDAY = ['date' => '2026-11-03', 'session_type' => 'easy', 'over_cap_minutes' => 9];

const HOT_SATURDAY = ['date' => '2026-11-07', 'session_type' => 'long', 'over_cap_minutes' => 14];

const TOO_HARD_DROP = ['date' => '2026-11-12', 'time_trial' => false, 'session_type' => 'tempo'];

/**
 * @param  list<array{date: string, session_type: string, over_cap_minutes: int}>  $hotDays
 * @param  array{date: string, session_type: string, time_trial: bool}|null  $drop
 * @return array{state: string, egregious: bool, hot_days: list<array{date: string, session_type: string, over_cap_minutes: int}>, drop: array{date: string, session_type: string, time_trial: bool}|null, blocker: string|null}
 */
function tooHardOutlook(string $state = EasyTooHardNotification::SET, bool $egregious = false, array $hotDays = [HOT_TUESDAY, HOT_SATURDAY], ?array $drop = TOO_HARD_DROP, ?string $blocker = null): array
{
    return ['state' => $state, 'egregious' => $egregious, 'hot_days' => $hotDays, 'drop' => $drop, 'blocker' => $blocker];
}

it('routes to web push alongside the inbox for a subscribed athlete', function (): void {
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh-key', 'auth-token');

    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::ONE_MORE, tooHardOutlook())->via($user))
        ->toBe([InAppChannel::class, IdempotentWebPushChannel::class]);
});

it('sends nothing once the master switch is off', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::SET, tooHardOutlook())->via($user))->toBe([]);
});

/** @return array{state: string, egregious: bool, hot_days: list<array{date: string, session_type: string, over_cap_minutes: int}>, drop: array{date: string, session_type: string, time_trial: bool}|null, blocker: string|null} */
function tooHardCase(string $case): array
{
    return match ($case) {
        'one more' => tooHardOutlook(state: EasyTooHardNotification::ONE_MORE, hotDays: [HOT_TUESDAY]),
        'one more, nothing next week to lose' => tooHardOutlook(state: EasyTooHardNotification::ONE_MORE, hotDays: [HOT_TUESDAY], drop: null),
        'set by one far over, near the race' => tooHardOutlook(egregious: true, hotDays: [[...HOT_SATURDAY, 'over_cap_minutes' => 31]], blocker: 'race week'),
        'set, nothing next week to lose' => tooHardOutlook(drop: null),
        'set by one far over, nothing next week to lose' => tooHardOutlook(egregious: true, hotDays: [[...HOT_SATURDAY, 'over_cap_minutes' => 31]], drop: null),
        'locked on sunday' => tooHardOutlook(blocker: '2 weeks before race week'),
        default => tooHardOutlook(),
    };
}

it('says which run went how far over, which day next week it costs, and whether it can come back', function (string $case, string $state, ?string $causeDate, string $title, string $body): void {
    $user = User::factory()->create();

    $message = new EasyTooHardNotification('2026-11-02', $state, tooHardCase($case), $causeDate)->toInbox($user);

    expect($message->kind)->toBe(NotificationKind::EasyTooHard)
        ->and($message->title)->toBe($title)
        ->and($message->body)->toBe($body)
        ->and($message->payload['url'])->toBe(route('plan'))
        ->and($message->dedupeKey)->toBe(EasyTooHardNotification::dedupeKeyFor(Carbon::parse('2026-11-02'), $state))
        ->and($message->dedupeKey)->toBe("easy_too_hard:2026-11-02:{$state}");
})->with([
    'one more' => [
        'one more', EasyTooHardNotification::ONE_MORE, '2026-11-03',
        "Thursday's tempo next week is at risk",
        "tuesday's easy run went 9 min over your heart-rate cap. one more easy day like it this week turns thursday's tempo next week into an easy run. you could bring it back from the plan on the day.",
    ],
    'one more, nothing next week to lose' => [
        'one more, nothing next week to lose', EasyTooHardNotification::ONE_MORE, '2026-11-03',
        'An easy run ran hot',
        "tuesday's easy run went 9 min over your heart-rate cap. keep the rest of this week easy.",
    ],
    'set by two' => [
        'set by two', EasyTooHardNotification::SET, '2026-11-07',
        "Next week, Thursday's tempo becomes an easy run",
        "saturday's long run went 14 min over your heart-rate cap. that is 2 easy days over it this week. thursday's tempo next week becomes an easy run. you can bring it back from the plan on the day. keep the rest of this week easy.",
    ],
    'set by one far over, near the race' => [
        'set by one far over, near the race', EasyTooHardNotification::SET, '2026-11-07',
        "Next week, Thursday's tempo becomes an easy run",
        "saturday's long run went 31 min over your heart-rate cap. that is far enough over to count for the whole week. thursday's tempo next week becomes an easy run. you can't bring it back: it's race week, and the weeks closest to a race stay as the plan set them. keep the rest of this week easy.",
    ],
    'set, nothing next week to lose' => [
        'set, nothing next week to lose', EasyTooHardNotification::SET, '2026-11-07',
        'Easy runs ran hot this week',
        "saturday's long run went 14 min over your heart-rate cap. that is 2 easy days over it this week. next week has no workout to lose. keep the rest of this week easy.",
    ],
    'set by one far over, nothing next week to lose' => [
        'set by one far over, nothing next week to lose', EasyTooHardNotification::SET, '2026-11-07',
        'An easy run ran hot this week',
        "saturday's long run went 31 min over your heart-rate cap. that is far enough over to count for the whole week. next week has no workout to lose. keep the rest of this week easy.",
    ],
    'locked on sunday' => [
        'locked on sunday', EasyTooHardNotification::LOCKED, null,
        "Next week, Thursday's tempo is an easy run",
        "tuesday's easy run (9 min) and saturday's long run (14 min) went over your heart-rate cap this week. thursday's tempo next week is an easy run. you can't bring it back: it's 2 weeks before race week, and the weeks closest to a race stay as the plan set them.",
    ],
]);

it('carries the same copy on telegram and web push', function (): void {
    $user = User::factory()->create();
    $notification = new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::ONE_MORE, tooHardOutlook());

    expect($notification->toTelegram($user)->text)->toContain("Thursday's tempo next week is at risk")->toContain(route('plan'))
        ->and($notification->toWebPush($user, $notification))->toBeInstanceOf(WebPushMessage::class);
});

it('goes stale once the week it warns about has ended, whatever weekday the locale starts on', function (string $locale): void {
    Carbon::setLocale($locale);

    expect(new EasyTooHardNotification('2026-11-02', EasyTooHardNotification::SET, tooHardOutlook())->staleAfter?->toDateTimeString())->toBe('2026-11-08 23:59:59');

    Carbon::setLocale('en');
})->with(['en', 'en_US']);
