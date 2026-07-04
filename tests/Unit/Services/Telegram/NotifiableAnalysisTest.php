<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\NotifiableAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recognises the notifiable types and ignores the rest', function (): void {
    $registry = new NotifiableAnalysis();

    $postRun = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $weekly = Analysis::factory()->make(['analysis_type' => AnalysisType::WeeklyRecap]);
    $monthly = Analysis::factory()->make(['analysis_type' => AnalysisType::MonthlyRecap]);
    $briefing = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingHeadline]);
    $greeting = Analysis::factory()->make(['analysis_type' => AnalysisType::DailyGreeting]);

    expect($registry->isNotifiable($postRun))->toBeTrue()
        ->and($registry->isNotifiable($weekly))->toBeTrue()
        ->and($registry->isNotifiable($monthly))->toBeTrue()
        ->and($registry->isNotifiable($briefing))->toBeTrue()
        ->and($registry->isNotifiable($greeting))->toBeFalse();
});

it('resolves the user behind a post-run speech via its activity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
    ]);

    expect((new NotifiableAnalysis())->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a weekly recap via its snapshot', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect((new NotifiableAnalysis())->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a monthly recap directly via its subject_id', function (): void {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'discriminator' => '2026-06',
    ]);

    expect((new NotifiableAnalysis())->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a daily briefing directly via its subject_id', function (): void {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::BriefingHeadline,
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'discriminator' => '2026-07-04',
    ]);

    expect((new NotifiableAnalysis())->resolveUser($analysis)?->id)->toBe($user->id);
});

it('isOptedIn returns true when the connection preference flag is on', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $connection = TelegramConnection::factory()->make(['notify_post_run' => true]);

    expect((new NotifiableAnalysis())->isOptedIn($analysis, $connection))->toBeTrue();
});

it('isOptedIn returns false when the connection preference flag is off', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $connection = TelegramConnection::factory()->make(['notify_post_run' => false]);

    expect((new NotifiableAnalysis())->isOptedIn($analysis, $connection))->toBeFalse();
});

it('isOptedIn returns true for a daily briefing when the connection has opted in', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingHeadline]);
    $connection = TelegramConnection::factory()->make(['notify_daily_briefing' => true]);

    expect((new NotifiableAnalysis())->isOptedIn($analysis, $connection))->toBeTrue();
});

it('isOptedIn returns false for a daily briefing by default (opt-in only)', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingHeadline]);
    $connection = TelegramConnection::factory()->make(['notify_daily_briefing' => false]);

    expect((new NotifiableAnalysis())->isOptedIn($analysis, $connection))->toBeFalse();
});

it('isOptedIn returns false for a non-notifiable type', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::DailyGreeting]);
    $connection = TelegramConnection::factory()->make();

    expect((new NotifiableAnalysis())->isOptedIn($analysis, $connection))->toBeFalse();
});

it('formats a post-run message with an emoji label, the content, and a deep link to the activity', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 123,
        'content' => 'Pace kamu konsisten banget.',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toStartWith('🏃 Pace kamu konsisten banget.')
        ->and($message)->toContain('Lihat detail lari: ' . route('aktivitas.show', 123));
});

it('includes a metrics line for a post-run notification', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5200,        // 5.20 km
        'moving_time' => 2054,     // 34:14, pace 6:35/km
        'average_heartrate' => 159,
    ]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
        'content' => 'Mantap!',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toContain('5.20 km · 34:14 · 6:35/km · 159 bpm');
});

it('omits HR from the metrics line on a strap-less run', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5200,
        'moving_time' => 2054,
        'average_heartrate' => null,
    ]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
        'content' => 'Mantap!',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toContain('5.20 km · 34:14 · 6:35/km')
        ->and($message)->not->toContain('bpm');
});

it('is recent enough to auto-notify when the activity started within the max age', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(2)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect((new NotifiableAnalysis())->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('is not recent enough to auto-notify when the activity is older than the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(10)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect((new NotifiableAnalysis())->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('treats a missing activity detail as recent enough (nothing to gate on)', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 999999,
    ]);

    expect((new NotifiableAnalysis())->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('never gates non-post-run types by activity age', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::WeeklyRecap]);

    expect((new NotifiableAnalysis())->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('links a weekly recap to the run history page', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'content' => 'Minggu ini 28 km.',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toStartWith('📊 Minggu ini 28 km.')
        ->and($message)->toContain('Lihat riwayat: ' . route('aktivitas.index'));
});

it('links a monthly recap to its month on the calendar', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'content' => 'Bulan ini 120 km.',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toStartWith('🗓️ Bulan ini 120 km.')
        ->and($message)->toContain('Lihat kalender: ' . route('kalender', ['month' => '2026-06']));
});

it('links a daily briefing to the dashboard', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::BriefingHeadline,
        'content' => 'Pagi ini enak buat lari santai.',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toStartWith('☀️ Pagi ini enak buat lari santai.')
        ->and($message)->toContain('Lihat ringkasan hari ini: ' . route('dashboard'));
});
