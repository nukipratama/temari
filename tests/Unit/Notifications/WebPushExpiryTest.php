<?php

declare(strict_types=1);

use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Models\AI\Analysis;
use App\Models\RaceGoal;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\MorningBriefingNotification;
use App\Notifications\RaceOutcomeNotification;
use App\Notifications\RaceTomorrowNotification;
use App\Notifications\StravaDisconnectedNotification;
use App\Notifications\StreakReminderNotification;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function pushOptions(Notification $notification, User $user): array
{
    return $notification->toWebPush($user, $notification)->getOptions();
}

function briefingOn(User $user, string $date): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => $date,
        'status' => AnalysisStatus::Done,
        'content' => 'easy 5k.',
    ]);
}

it('expires the briefing push at the end of its day and replaces an older one', function (): void {
    Carbon::setTestNow('2026-05-24 06:30:00');
    $user = User::factory()->create();

    $options = pushOptions(new MorningBriefingNotification(briefingOn($user, '2026-05-24')), $user);

    expect($options['TTL'])->toBe(17 * 3600 + 29 * 60 + 59)
        ->and($options['topic'])->toBe('briefing');
});

it('gives a briefing released after its day the minimum TTL', function (): void {
    Carbon::setTestNow('2026-05-25 04:00:00');
    $user = User::factory()->create();

    expect(pushOptions(new MorningBriefingNotification(briefingOn($user, '2026-05-24')), $user)['TTL'])->toBe(1);
});

it('expires the race-tomorrow push at 06:00 on race day', function (): void {
    Carbon::setTestNow('2026-05-23 18:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-05-24']);

    $options = pushOptions(new RaceTomorrowNotification($race), $user);

    expect($options['TTL'])->toBe(12 * 3600)
        ->and($options)->not->toHaveKey('topic');
});

it('expires the streak push on Sunday 23:59 and replaces an older one', function (): void {
    Carbon::setTestNow('2026-05-23 18:00:00');
    $user = User::factory()->create();

    $options = pushOptions(new StreakReminderNotification(3), $user);

    expect($options['TTL'])->toBe(30 * 3600 - 60)
        ->and($options['topic'])->toBe('streak');
});

it('keeps the recap, race outcome, strava and test pushes for their fixed windows', function (): void {
    $user = User::factory()->create();
    $analysis = briefingOn($user, '2026-05-24');
    $race = RaceGoal::factory()->for($user)->completed()->create();

    expect(pushOptions(new AnalysisReadyNotification($analysis), $user)['TTL'])->toBe(3 * 86400)
        ->and(pushOptions(new RaceOutcomeNotification($race), $user)['TTL'])->toBe(3 * 86400)
        ->and(pushOptions(new StravaDisconnectedNotification(now()), $user)['TTL'])->toBe(7 * 86400)
        ->and(pushOptions(new TestNotification(), $user)['TTL'])->toBe(300);
});


it('leaves no notification with a web push and no TTL', function (): void {
    $sources = collect(File::files(app_path('Notifications')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'function toWebPush'));

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $file) {
        expect($file->getContents())->toContain("'TTL'");
    }
});
