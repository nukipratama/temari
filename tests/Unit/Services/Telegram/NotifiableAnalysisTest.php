<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\NotifiableAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
        ->and($registry->isNotifiable($briefing))->toBeFalse() // daily briefing no longer notifies
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

    expect(new NotifiableAnalysis()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a weekly recap via its snapshot', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotifiableAnalysis()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('resolves the user behind a monthly recap directly via its subject_id', function (): void {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'discriminator' => '2026-06',
    ]);

    expect(new NotifiableAnalysis()->resolveUser($analysis)?->id)->toBe($user->id);
});

it('isOptedIn returns true when the preference flag is on', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => true]);

    expect(new NotifiableAnalysis()->isOptedIn($analysis, $user))->toBeTrue();
});

it('isOptedIn returns false when the preference flag is off', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect(new NotifiableAnalysis()->isOptedIn($analysis, $user))->toBeFalse();
});

it('isOptedIn defaults to opted-in when the user has no preference row', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::PostRunSpeech]);
    $user = User::factory()->create();

    expect(new NotifiableAnalysis()->isOptedIn($analysis, $user))->toBeTrue();
});

it('isOptedIn returns false for a non-notifiable type', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::DailyGreeting]);
    $user = User::factory()->create();

    expect(new NotifiableAnalysis()->isOptedIn($analysis, $user))->toBeFalse();
});

it('formats a post-run message with the title line, a blank line, the content, and a deep link to the activity', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 123, // no ActivityDetail → distance-less title
        'content' => 'Pace kamu konsisten banget.',
    ]);

    $message = new NotifiableAnalysis()->format($analysis);

    expect($message)->toStartWith("🏃 Lari kamu udah masuk! 🏁\n\nPace kamu konsisten banget.")
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

    $message = new NotifiableAnalysis()->format($analysis);

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

    $message = new NotifiableAnalysis()->format($analysis);

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

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('is not recent enough to auto-notify when the activity is older than the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDays(10)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('treats a missing activity detail as recent enough (nothing to gate on)', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 999999,
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('auto-notifies a weekly recap whose week ended within the max age', function (): void {
    $snapshot = WeeklySnapshot::factory()->create(['week_ending' => now()->subDays(1)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('does not auto-notify a weekly recap whose week ended before the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $snapshot = WeeklySnapshot::factory()->create(['week_ending' => now()->subDays(30)]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
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

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('does not auto-notify a monthly recap whose month ended before the max age', function (): void {
    config(['services.telegram.notify_max_age_days' => 3]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'subject_id' => 1,
        'discriminator' => now()->subMonths(6)->format('Y-m'),
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeFalse();
});

it('treats a missing weekly snapshot as recent enough (nothing to gate on)', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => 999999,
    ]);

    expect(new NotifiableAnalysis()->isRecentEnoughToAutoNotify($analysis))->toBeTrue();
});

it('links a weekly recap to the run history page', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'content' => 'Minggu ini 28 km.',
    ]);

    $message = new NotifiableAnalysis()->format($analysis);

    expect($message)->toStartWith("📊 Rekap minggu lalu udah siap\n\nMinggu ini 28 km.")
        ->and($message)->toContain('Lihat riwayat: ' . route('aktivitas.index'));
});

it('links a monthly recap to its month on the calendar', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'content' => 'Bulan ini 120 km.',
    ]);

    $message = new NotifiableAnalysis()->format($analysis);

    expect($message)->toStartWith("🗓️ Rekap Juni udah siap\n\nBulan ini 120 km.")
        ->and($message)->toContain('Lihat kalender: ' . route('kalender', ['month' => '2026-06']));
});

// --- title() ---------------------------------------------------------------

it('builds a post-run title carrying the run distance', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 8230]); // 8.23 km → 8,2K
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('🏃 Lari 8,2K kamu udah masuk! 🏁');
});

it('drops the ",0" so a whole-kilometre run reads as "5K"', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 5000]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('🏃 Lari 5K kamu udah masuk! 🏁');
});

it('falls back to a distance-less post-run title when the activity has no detail', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 999999,
    ]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('🏃 Lari kamu udah masuk! 🏁');
});

it('builds a monthly-recap title naming the Indonesian month', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-07',
    ]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('🗓️ Rekap Juli udah siap');
});

it('falls back to the label when the monthly-recap discriminator is missing', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => null,
    ]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('🗓️ Rekap bulanan udah siap');
});

it('uses the static label for the weekly recap title', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::WeeklyRecap]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('📊 Rekap minggu lalu udah siap');
});

// Tapping "your weekly recap is ready" should land on *that* week, the way the
// monthly recap already lands on its month — not on the bare run history.
it('deep-links the weekly recap to its own week', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_id' => $snapshot->id,
    ]);

    expect(new NotifiableAnalysis()->url($analysis))
        ->toBe(route('aktivitas.index', ['week' => '2026-05-17']));
});

// A deleted week must not turn the notification into a dead end.
it('falls back to the bare run history when the recap snapshot is gone', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_id' => 99_999,
    ]);

    expect(new NotifiableAnalysis()->url($analysis))->toBe(route('aktivitas.index'));
});

it('falls back to the app name for a non-notifiable type', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::DailyGreeting]);

    expect(new NotifiableAnalysis()->title($analysis))->toBe('Temari');
});
