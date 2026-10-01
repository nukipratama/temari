<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-02 08:00:00');
    Http::preventStrayRequests();
    Queue::fake();
});
afterEach(fn () => Carbon::setTestNow());

/**
 * A user with one run and one past tempo day graded under the old policy.
 *
 * @return array{0: User, 1: PlannedSession, 2: Activity, 3: Analysis}
 */
function resetAthlete(bool $demo = false): array
{
    $user = $demo ? User::factory()->demo()->create() : User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-09-24 06:00:00'),
        'distance' => 9_000,
        'stream_summary' => null,
    ]);
    ActivityStream::factory()->for($activity)->create();
    $day = PlannedSession::factory()->for($user)->create([
        'date' => '2026-09-24',
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 17,
        'prescribed_pace_band' => PaceBand::Threshold,
        'prescribed_pace_sec_per_km' => 301,
        'prescription_reason' => 'written under the old policy',
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 97,
        'intent_verdict' => IntentVerdict::Hit,
    ]);
    $narration = Analysis::factory()->done('Read under the old policy')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
    ]);

    return [$user, $day, $activity, $narration];
}

it('prints before and after counts on a dry run and keeps nothing', function (): void {
    [$user, $day, $activity, $narration] = resetAthlete();
    $dayBefore = $day->fresh()->getAttributes();

    $this->artisan('coaching:reset', ['--dry-run' => true])
        ->expectsOutputToContain("user {$user->id} (dry run):")
        ->expectsOutputToContain('past days done')
        ->expectsOutputToContain('Dry run: nothing was kept for 1 non-demo user(s)')
        ->assertSuccessful();

    expect($user->fresh()->coaching_reset_at)->toBeNull()
        ->and($day->fresh()->getAttributes())->toBe($dayBefore)
        ->and($activity->detail->fresh()->stream_summary)->toBeNull()
        ->and($narration->fresh()->stale_at)->toBeNull();
});

it('rebuilds derived state, re-grades history and marks narration stale, leaving raw runs and cost history alone', function (): void {
    [$user, $day, $activity, $narration] = resetAthlete();
    $raw = Arr::only($activity->detail->fresh()->getAttributes(), ['distance', 'moving_time', 'elapsed_time', 'start_date_local', 'average_heartrate']);
    $usage = TokenUsage::query()->create([
        'user_id' => $user->id,
        'analysis_id' => $narration->id,
        'kind' => 'run_insight',
        'model' => 'gpt-4o',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);
    $usage = $usage->fresh();

    $this->artisan('coaching:reset', ['--user' => $user->id])
        ->expectsOutputToContain('Reset 1 non-demo user(s); 0 already reset.')
        ->assertSuccessful();

    expect($user->fresh()->coaching_reset_at)->not->toBeNull()
        ->and($activity->detail->fresh()->stream_summary)->not->toBeNull()
        ->and(Arr::only($activity->detail->fresh()->getAttributes(), array_keys($raw)))->toBe($raw)
        ->and($day->fresh()->prescription_reason)->not->toBe('written under the old policy')
        ->and($narration->fresh()->stale_at)->not->toBeNull()
        ->and($narration->fresh()->content)->toBe('Read under the old policy')
        ->and($usage->fresh()->getAttributes())->toBe($usage->getAttributes())
        ->and(PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '>=', Carbon::today())->exists())->toBeTrue();

    Queue::assertNothingPushed();
});

it('is a no-op on a repeat apply', function (): void {
    [$user, $day] = resetAthlete();
    $this->artisan('coaching:reset')->assertSuccessful();
    $after = $day->fresh()->getAttributes();
    $resetAt = $user->fresh()->coaching_reset_at;

    Carbon::setTestNow('2026-10-02 09:00:00');
    $this->artisan('coaching:reset')
        ->expectsOutputToContain("user {$user->id}: already reset, nothing to do")
        ->expectsOutputToContain('Reset 0 non-demo user(s); 1 already reset.')
        ->assertSuccessful();

    expect($day->fresh()->getAttributes())->toBe($after)
        ->and($user->fresh()->coaching_reset_at->equalTo($resetAt))->toBeTrue();
});

it('never resets the demo athlete or a user outside --user', function (): void {
    [$demo, $demoDay] = resetAthlete(demo: true);
    [$other] = resetAthlete();
    [$selected] = resetAthlete();
    $demoBefore = $demoDay->fresh()->getAttributes();

    $this->artisan('coaching:reset', ['--user' => $selected->id])->assertSuccessful();
    $this->artisan('coaching:reset', ['--user' => $demo->id])
        ->expectsOutputToContain('Reset 0 non-demo user(s); 0 already reset.')
        ->assertSuccessful();

    expect($selected->fresh()->coaching_reset_at)->not->toBeNull()
        ->and($other->fresh()->coaching_reset_at)->toBeNull()
        ->and($demo->fresh()->coaching_reset_at)->toBeNull()
        ->and($demoDay->fresh()->getAttributes())->toBe($demoBefore);
});
