<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Seed a user with one run (+ card, story line, PR), a weekly snapshot, an
 * activity-subject, a personal-record-subject and a user-subject analysis,
 * and two token-usage rows.
 *
 * @return array{user: User, activity: Activity, card: RunCard, snapshot: WeeklySnapshot, personalRecord: PersonalRecord}
 */
function seedUserWithData(): array
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create();
    $card = RunCard::factory()->for($activity)->create();
    StoryLine::factory()->for($user)->create(['activity_id' => $activity->id]);
    $personalRecord = PersonalRecord::factory()->for($user)->create(['activity_id' => $activity->id]);
    $snapshot = WeeklySnapshot::factory()->for($user)->create();

    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    Analysis::factory()->create([
        'subject_type' => PersonalRecord::class,
        'subject_id' => $personalRecord->id,
        'analysis_type' => AnalysisType::PrContext,
        'discriminator' => null,
    ]);
    Analysis::factory()->create([
        'subject_type' => AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => '2026-05-18',
    ]);

    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'post_run_speech',
        'prompt_tokens' => 100,
        'completion_tokens' => 40,
        'total_tokens' => 140,
        'created_at' => now(),
    ]);
    TokenUsage::query()->create([
        'user_id' => $user->id,
        'kind' => 'daily_greeting',
        'prompt_tokens' => 20,
        'completion_tokens' => 10,
        'total_tokens' => 30,
        'created_at' => now(),
    ]);

    return ['user' => $user, 'activity' => $activity, 'card' => $card, 'snapshot' => $snapshot, 'personalRecord' => $personalRecord];
}

it('removes the user and all owned data, deletes their analyses, but keeps ai_token_usages', function (): void {
    ['user' => $user, 'activity' => $activity, 'card' => $card, 'snapshot' => $snapshot, 'personalRecord' => $personalRecord] = seedUserWithData();
    $bystander = seedUserWithData();

    $this->artisan('user:remove', ['id' => $user->id, '--force' => true])
        ->assertSuccessful();

    // User and every cascaded owned row is gone.
    expect(User::query()->find($user->id))->toBeNull()
        ->and(Activity::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(RunCard::query()->whereKey($card->id)->exists())->toBeFalse()
        ->and(StoryLine::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(PersonalRecord::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(WeeklySnapshot::query()->whereKey($snapshot->id)->exists())->toBeFalse();

    // All analyses (activity-subject + personal-record-subject + user-subject) are deleted.
    expect(Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->exists())->toBeFalse()
        ->and(Analysis::query()->where('subject_type', PersonalRecord::class)->where('subject_id', $personalRecord->id)->exists())->toBeFalse()
        ->and(Analysis::query()->where('subject_type', AnalysisType::DAILY_GREETING_SUBJECT_TYPE)->where('subject_id', $user->id)->exists())->toBeFalse();

    // Token-usage rows are kept (now orphaned) for cost history.
    expect(TokenUsage::query()->where('user_id', $user->id)->count())->toBe(2);

    // The bystander user is completely untouched.
    expect(User::query()->find($bystander['user']->id))->not->toBeNull()
        ->and(Activity::query()->where('user_id', $bystander['user']->id)->exists())->toBeTrue()
        ->and(Analysis::query()->where('subject_id', $bystander['activity']->id)->where('subject_type', Activity::class)->exists())->toBeTrue()
        ->and(TokenUsage::query()->where('user_id', $bystander['user']->id)->count())->toBe(2);
});

it('refuses to remove the demo user', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    $this->artisan('user:remove', ['id' => $demo->id, '--force' => true])
        ->assertFailed();

    expect(User::query()->find($demo->id))->not->toBeNull();
});

it('errors when the user does not exist', function (): void {
    $this->artisan('user:remove', ['id' => 999999, '--force' => true])
        ->assertFailed();
});

it('aborts and removes nothing when the confirmation is declined', function (): void {
    ['user' => $user] = seedUserWithData();

    $this->artisan('user:remove', ['id' => $user->id])
        ->expectsConfirmation('Permanently remove user '.$user->id.' and all owned data? This cannot be undone.', 'no')
        ->assertSuccessful();

    expect(User::query()->find($user->id))->not->toBeNull()
        ->and(Activity::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('removes the user after an interactive confirmation', function (): void {
    ['user' => $user] = seedUserWithData();

    $this->artisan('user:remove', ['id' => $user->id])
        ->expectsConfirmation('Permanently remove user '.$user->id.' and all owned data? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect(User::query()->find($user->id))->toBeNull();
});
