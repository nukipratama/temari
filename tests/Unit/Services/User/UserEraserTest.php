<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\User\UserEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * One ai_analyses row for every subject shape the table carries, so a change to
 * the subject list cannot silently start leaking one of them.
 */
function analysesForEverySubjectShape(User $user): void
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $record = PersonalRecord::factory()->for($user)->create();

    foreach ([
        [Activity::class, $activity->id, AnalysisType::PostRunSpeech],
        [RunCard::class, $card->id, AnalysisType::CardFlavor],
        [WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap],
        [PersonalRecord::class, $record->id, AnalysisType::PrContext],
        [AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice],
        ['daily_greeting_user_day', $user->id, AnalysisType::BriefingMascotVoice],
        ['trend_caption_user_day', $user->id, AnalysisType::BriefingMascotVoice],
        ['persona_summary_user', $user->id, AnalysisType::BriefingMascotVoice],
        [AnalysisType::PROFILE_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::ProfileVoice],
        [AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap],
        [AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead],
    ] as [$subjectType, $subjectId, $type]) {
        Analysis::factory()->done('x')->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'analysis_type' => $type,
            'discriminator' => match ($type) {
                AnalysisType::MonthlyRecap => '2026-05',
                AnalysisType::TrendRead => '30d',
                default => null,
            },
        ]);
    }
}

function pushEndpointFor(User $user, string $endpoint): void
{
    DB::table('push_subscriptions')->insert([
        'subscribable_type' => $user->getMorphClass(),
        'subscribable_id' => $user->id,
        'endpoint' => $endpoint,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('removes every ai_analyses subject shape the table can hold', function (): void {
    $user = User::factory()->create();
    analysesForEverySubjectShape($user);
    expect(Analysis::query()->count())->toBe(11);

    app(UserEraser::class)->erase($user);

    expect(Analysis::query()->count())->toBe(0)
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('removes the push endpoints nothing else would', function (): void {
    $user = User::factory()->create();
    pushEndpointFor($user, 'https://push.example.test/a');
    pushEndpointFor($user, 'https://push.example.test/b');

    app(UserEraser::class)->erase($user);

    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

it('counts the orphans it would remove without removing them', function (): void {
    $user = User::factory()->create();
    analysesForEverySubjectShape($user);
    pushEndpointFor($user, 'https://push.example.test/a');

    $counts = app(UserEraser::class)->orphanCounts($user);

    expect($counts)->toBe(['ai_analyses' => 11, 'push_subscriptions' => 1])
        // Read-only: a preview must not delete what it is previewing.
        ->and(Analysis::query()->count())->toBe(11)
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('reaches a retired-type row the KnownAnalysisTypeScope hides from default queries', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    DB::table('ai_analyses')->insert([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => 'run_insight_technical',
        'discriminator' => null,
        'status' => 'done',
        'content' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(UserEraser::class)->orphanCounts($user)['ai_analyses'])->toBe(1);

    app(UserEraser::class)->erase($user);

    expect(DB::table('ai_analyses')->where('subject_id', $activity->id)->count())->toBe(0);
});

it('leaves another user narration and endpoints alone', function (): void {
    $user = User::factory()->create();
    $bystander = User::factory()->create();
    analysesForEverySubjectShape($user);
    analysesForEverySubjectShape($bystander);
    pushEndpointFor($bystander, 'https://push.example.test/bystander');

    app(UserEraser::class)->erase($user);

    expect(Analysis::query()->count())->toBe(11)
        ->and(DB::table('push_subscriptions')->count())->toBe(1)
        ->and(User::query()->whereKey($bystander->id)->exists())->toBeTrue();
});

it('stamps the name and Strava id onto cost history before the account goes', function (): void {
    $user = User::factory()->create(['name' => 'Mantan Pelari']);
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 424242]);
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'briefing',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);

    app(UserEraser::class)->erase($user);

    $row = TokenUsage::query()->where('user_id', $user->id)->sole();
    expect($row->user_name)->toBe('Mantan Pelari')
        ->and($row->strava_athlete_id)->toBe(424242);
});

it('stamps a null Strava id for an account that never connected one', function (): void {
    $user = User::factory()->create(['name' => 'Belum Nyambung']);
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'briefing',
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
        'total_tokens' => 2,
    ]);

    app(UserEraser::class)->erase($user);

    $row = TokenUsage::query()->where('user_id', $user->id)->sole();
    expect($row->user_name)->toBe('Belum Nyambung')
        ->and($row->strava_athlete_id)->toBeNull();
});

it('leaves another user cost history unstamped', function (): void {
    $user = User::factory()->create();
    $bystander = User::factory()->create(['name' => 'Masih Lari']);
    TokenUsage::query()->create([
        'user_id' => $bystander->id,
        'kind' => 'briefing',
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
        'total_tokens' => 2,
    ]);

    app(UserEraser::class)->erase($user);

    expect(TokenUsage::query()->where('user_id', $bystander->id)->sole()->user_name)->toBeNull();
});
