<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Telegram\AnalysisMessagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('formats a post-run message with the title line, a blank line, the content, and a deep link to the activity', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 123, // no ActivityDetail → distance-less title
        'content' => 'Pace kamu konsisten banget.',
    ]);

    $message = new AnalysisMessagePresenter()->format($analysis);

    expect($message)->toStartWith("🏃 Lari kamu udah masuk! 🏁\n\nPace kamu konsisten banget.")
        ->and($message)->toContain('Lihat detail lari: ' . route('aktivitas.show', 123));
});

it('includes a metrics line for a post-run notification', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5200,        // 5,2 km
        'moving_time' => 2054,     // 34:14, pace 6:35/km
        'average_heartrate' => 159,
    ]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
        'content' => 'Mantap!',
    ]);

    $message = new AnalysisMessagePresenter()->format($analysis);

    expect($message)->toContain('5,2 km · 34:14 · 6:35/km · 159 bpm');
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

    $message = new AnalysisMessagePresenter()->format($analysis);

    expect($message)->toContain('5,2 km · 34:14 · 6:35/km')
        ->and($message)->not->toContain('bpm');
});

it('links a weekly recap to the run history page', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'content' => 'Minggu ini 28 km.',
    ]);

    $message = new AnalysisMessagePresenter()->format($analysis);

    expect($message)->toStartWith("📊 Rekap minggu lalu udah siap\n\nMinggu ini 28 km.")
        ->and($message)->toContain('Lihat riwayat: ' . route('aktivitas.index'));
});

it('links a monthly recap to its month on the calendar', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-06',
        'content' => 'Bulan ini 120 km.',
    ]);

    $message = new AnalysisMessagePresenter()->format($analysis);

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

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('🏃 Lari 8,2K kamu udah masuk! 🏁');
});

it('drops the ",0" so a whole-kilometre run reads as "5K"', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 5000]);
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => $activity->id,
    ]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('🏃 Lari 5K kamu udah masuk! 🏁');
});

it('falls back to a distance-less post-run title when the activity has no detail', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_id' => 999999,
    ]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('🏃 Lari kamu udah masuk! 🏁');
});

it('builds a monthly-recap title naming the Indonesian month', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => '2026-07',
    ]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('🗓️ Rekap Juli udah siap');
});

it('falls back to the label when the monthly-recap discriminator is missing', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => null,
    ]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('🗓️ Rekap bulanan udah siap');
});

it('uses the static label for the weekly recap title', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::WeeklyRecap]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('📊 Rekap minggu lalu udah siap');
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

    expect(new AnalysisMessagePresenter()->url($analysis))
        ->toBe(route('aktivitas.index', ['week' => '2026-05-17']));
});

// A deleted week must not turn the notification into a dead end.
it('falls back to the bare run history when the recap snapshot is gone', function (): void {
    $analysis = Analysis::factory()->make([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_id' => 99_999,
    ]);

    expect(new AnalysisMessagePresenter()->url($analysis))->toBe(route('aktivitas.index'));
});

it('falls back to the app name for a non-notifiable type', function (): void {
    $analysis = Analysis::factory()->make(['analysis_type' => AnalysisType::BriefingMascotVoice]);

    expect(new AnalysisMessagePresenter()->title($analysis))->toBe('Temari');
});
