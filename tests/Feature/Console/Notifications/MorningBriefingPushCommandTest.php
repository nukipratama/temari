<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\MorningBriefingNotification;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

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

    if ($briefingStatus !== null) {
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

it('pushes to an athlete whose usual start falls in this quarter hour', function (): void {
    Notification::fake();

    $user = morningAthlete();

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 1 athletes.')
        ->assertSuccessful();

    Notification::assertSentTo($user, MorningBriefingNotification::class);
});

it('leaves an athlete alone outside their own bucket', function (string $usualStart): void {
    Notification::fake();

    morningAthlete($usualStart);

    $this->artisan('briefing:morning-push')
        ->expectsOutputToContain('Pushed the morning briefing to 0 athletes.')
        ->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'the quarter hour before' => '05:50:00',
    'the quarter hour after' => '06:20:00',
    'the evening' => '19:00:00',
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
    expect(Analysis::query()->count())->toBe($status === null ? 0 : 1);
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
