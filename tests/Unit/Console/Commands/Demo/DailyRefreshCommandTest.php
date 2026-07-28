<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function dailyRefreshDemoUser(): User
{
    return User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
}

function dailyRefreshTodayRunCount(User $user): int
{
    return ActivityDetail::query()
        ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
        ->where('activities.user_id', $user->id)
        ->whereDate('activity_details.start_date_local', Carbon::today())
        ->count();
}

/** Every date-keyed Temari surface for today/this week must be filled (no "Belum dibaca"). */
function expectDemoDateKeyedNarrationDone(User $user): void
{
    $today = Carbon::today()->toDateString();
    $week = Carbon::now()->isoFormat('GGGG-[W]WW');

    $cases = [
        [AnalysisType::BRIEFING_SUBJECT_TYPE, AnalysisType::BriefingSuggestion, $today],
        [AnalysisType::BRIEFING_SUBJECT_TYPE, AnalysisType::BriefingMascotVoice, $today],
        [AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE, AnalysisType::PersonaSummary, $week],
    ];

    foreach ($cases as [$subjectType, $type, $discriminator]) {
        $row = Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $user->id)
            ->where('analysis_type', $type)
            ->where('discriminator', $discriminator)
            ->first();

        expect($row)->not->toBeNull("missing {$type->value} row for {$discriminator}")
            ->and($row->status)->toBe(AnalysisStatus::Done)
            ->and($row->content)->not->toBeEmpty();
    }
}

it('adds one fresh run today and fills today\'s narration with zero queued jobs on a run day', function (): void {
    Carbon::setTestNow('2026-05-12 00:30:00'); // Tuesday — a run day

    $this->artisan('demo:daily-refresh')->assertSuccessful();

    $user = dailyRefreshDemoUser();

    expect(dailyRefreshTodayRunCount($user))->toBe(1);
    expectDemoDateKeyedNarrationDone($user);

    // The new run's detail page is fully filled (speech + the three insights).
    $todayActivityId = Activity::query()->where('user_id', $user->id)->max('id');
    foreach ([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
    ] as $type) {
        $row = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $todayActivityId)
            ->where('analysis_type', $type)
            ->first();
        expect($row?->status)->toBe(AnalysisStatus::Done);
    }

    // Zero LLM tokens: nothing was queued/processing (all rule-based-filled).
    expect(Analysis::query()->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Processing])->count())
        ->toBe(0);
});

it('refreshes today\'s narration without adding a run on a rest day', function (): void {
    Carbon::setTestNow('2026-05-11 00:30:00'); // Monday — a rest day

    $this->artisan('demo:daily-refresh')->assertSuccessful();

    $user = dailyRefreshDemoUser();

    expect(Activity::query()->where('user_id', $user->id)->count())->toBe(0);
    expectDemoDateKeyedNarrationDone($user);
});

it('is idempotent on a same-day re-run', function (): void {
    Carbon::setTestNow('2026-05-12 00:30:00'); // Tuesday — a run day

    $this->artisan('demo:daily-refresh')->assertSuccessful();
    $this->artisan('demo:daily-refresh')->assertSuccessful();

    expect(dailyRefreshTodayRunCount(dailyRefreshDemoUser()))->toBe(1);
});
