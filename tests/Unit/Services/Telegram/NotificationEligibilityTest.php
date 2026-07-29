<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\NotificationEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('recognises the notifiable types and ignores the rest', function (): void {
    $registry = new NotificationEligibility();

    $postRun = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $weekly = Analysis::factory()->make(['analysis_type' => AnalysisType::WeeklyRecap]);
    $monthly = Analysis::factory()->make(['analysis_type' => AnalysisType::MonthlyRecap]);
    $briefing = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingSuggestion]);
    $mascotVoice = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingMascotVoice]);

    expect($registry->isNotifiable($postRun))->toBeTrue()
        ->and($registry->isNotifiable($weekly))->toBeTrue()
        ->and($registry->isNotifiable($monthly))->toBeTrue()
        ->and($registry->isNotifiable($briefing))->toBeFalse() // daily briefing no longer notifies
        ->and($registry->isNotifiable($mascotVoice))->toBeFalse();
});

it('resolves the user behind a post-run speech via its activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
    ]);

    expect(new NotificationEligibility()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a weekly recap via its snapshot', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotificationEligibility()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a monthly recap directly via its subject_id', function (): void {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'discriminator' => '2026-06',
    ]);

    expect(new NotificationEligibility()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('isOptedIn returns true when the preference flag is on', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => true]);

    expect(new NotificationEligibility()->isOptedIn($analysis, $user))->toBeTrue();
});

it('isOptedIn returns false when the preference flag is off', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(new NotificationEligibility()->isOptedIn($analysis, $user))->toBeFalse();
});

it('isOptedIn defaults to opted-in when the user has no preference row', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();

    expect(new NotificationEligibility()->isOptedIn($analysis, $user))->toBeTrue();
});

it('isOptedIn returns false for a non-notifiable type', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingMascotVoice]);
    $user = User::factory()->create();

    expect(new NotificationEligibility()->isOptedIn($analysis, $user))->toBeFalse();
});

it('is recent enough to auto-notify when the activity started within the max age', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(2)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('is not recent enough to auto-notify when the activity is older than the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(10)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('treats a missing activity detail as recent enough (nothing to gate on)', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 999999,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('auto-notifies a weekly recap whose week ended within the max age', function (): void {
    $snapshot = WeeklySnapshot::factory()->create(['week_ending' => now()->subDays(1)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('does not auto-notify a weekly recap whose week ended before the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $snapshot = WeeklySnapshot::factory()->create(['week_ending' => now()->subDays(30)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('auto-notifies a monthly recap whose month ended within the max age', function (): void {
    // The recap only fires right after the month closes (ai:monthly-recap runs on
    // the 1st), so pin "now" to just after a month boundary to assert the fresh case.
    $this->travelTo(Carbon::parse('2026-07-01 06:00'));
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_id' => 1,
        'discriminator' => '2026-06',
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('does not auto-notify a monthly recap whose month ended before the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_id' => 1,
        'discriminator' => now()->subMonths(6)->format('Y-m'),
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('treats a missing weekly snapshot as recent enough (nothing to gate on)', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => 999999,
    ]);

    expect(new NotificationEligibility()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});
