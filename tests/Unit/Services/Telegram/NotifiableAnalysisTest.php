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
    $greeting = Analysis::factory()->make(['analysis_type' => AnalysisType::DailyGreeting]);

    expect($registry->isNotifiable($postRun))->toBeTrue()
        ->and($registry->isNotifiable($weekly))->toBeTrue()
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

it('links a weekly recap to the run history page', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'content' => 'Minggu ini 28 km.',
    ]);

    $message = (new NotifiableAnalysis())->format($analysis);

    expect($message)->toStartWith('📊 Minggu ini 28 km.')
        ->and($message)->toContain('Lihat riwayat: ' . route('aktivitas.index'));
});
