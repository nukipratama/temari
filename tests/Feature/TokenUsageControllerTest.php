<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Dead-letter a WeeklyRecap for $user (Failed, budget burned). */
function deadLetterWeeklyRecap(User $user): Analysis
{
    $snap = WeeklySnapshot::factory()->for($user)->create();

    return Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
}

function seedUsage(
    string $kind,
    int $prompt,
    int $completion,
    Carbon $when,
    ?int $latencyMs = null,
    bool $truncated = false,
    ?int $userId = null,
): void {
    TokenUsage::query()->create([
        'user_id' => $userId,
        'kind' => $kind,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'model' => 'gpt-test',
        'latency_ms' => $latencyMs,
        'truncated' => $truncated,
        'created_at' => $when,
    ]);
}

it('is reachable without a Laravel session (edge auth handles access in prod)', function (): void {
    $this->get('/ai-usage')->assertSuccessful();
});

it('renders the AiUsage page with totals + per-kind breakdown filtered by date', function (): void {
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00:00'), latencyMs: 800);
    seedUsage('briefing', 200, 80, Carbon::parse('2026-05-15 11:00:00'), latencyMs: 1200, truncated: true);
    seedUsage('run-insight', 300, 150, Carbon::parse('2026-05-12 13:00:00'), latencyMs: 2400);
    seedUsage('briefing', 999, 999, Carbon::parse('2026-04-30 23:00:00')); // outside range

    $this->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('AiUsage')
                ->where('from', '2026-05-01')
                ->where('to', '2026-05-19')
                ->where('totals', [
                    'prompt' => 600,
                    'completion' => 280,
                    'total' => 880,
                    'calls' => 3,
                    'truncated_calls' => 1,
                    'cost' => 0,
                ])
                ->has('byKind', 2)
                ->where('byKind.0', [
                    'kind' => 'run-insight',
                    'prompt' => 300,
                    'completion' => 150,
                    'total' => 450,
                    'calls' => 1,
                    'truncated_calls' => 0,
                    'avg_latency_ms' => 2400,
                    'max_latency_ms' => 2400,
                    'cost' => 0,
                ])
                ->where('byKind.1', [
                    'kind' => 'briefing',
                    'prompt' => 300,
                    'completion' => 130,
                    'total' => 430,
                    'calls' => 2,
                    'truncated_calls' => 1,
                    'avg_latency_ms' => 1000,
                    'max_latency_ms' => 1200,
                    'cost' => 0,
                ])
                ->has('byDeployment')
                ->has('budget'),
        );
});

it('defaults to the rolling last 7 days when no range is given', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00'); // 7d window = 2026-05-13 .. now
    seedUsage('inside', 50, 50, Carbon::parse('2026-05-15'));
    seedUsage('outside', 50, 50, Carbon::parse('2026-05-10')); // older than 7 days

    $this->get('/ai-usage')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('range', '7d')
                ->where('from', '2026-05-13')
                ->where('totals.calls', 1),
        );

    Carbon::setTestNow();
});

it('resolves relative range tokens to self-correcting windows', function (string $range, string $expectedFrom): void {
    Carbon::setTestNow('2026-05-19 12:00:00');

    $this->get("/ai-usage?range={$range}")
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('range', $range)
                ->where('from', $expectedFrom),
        );

    Carbon::setTestNow();
})->with([
    'today' => ['today', '2026-05-19'],
    '7d' => ['7d', '2026-05-13'],
    '30d' => ['30d', '2026-04-20'],
    'month' => ['month', '2026-05-01'],
    'all' => ['all', '1970-01-01'],
]);

it('maps legacy absolute from+to links (no range) to a custom range', function (): void {
    $this->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('range', 'custom')
                ->where('from', '2026-05-01')
                ->where('to', '2026-05-19'),
        );
});

it('includes previousTotals for a bounded range and null for all-time', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-15')); // current 7d window
    seedUsage('briefing', 40, 20, Carbon::parse('2026-05-10')); // prior window

    $this->get('/ai-usage?range=7d')
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('totals.total', 150)
                ->where('previousTotals.total', 60),
        );

    $this->get('/ai-usage?range=all')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('previousTotals', null));

    Carbon::setTestNow();
});

it('rejects malformed date inputs', function (): void {
    $this->getJson('/ai-usage?from=yesterday')->assertStatus(422);
});

it('returns zeroed totals and empty breakdown when no rows fall within range', function (): void {
    $this->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('totals', [
                    'prompt' => 0,
                    'completion' => 0,
                    'total' => 0,
                    'calls' => 0,
                    'truncated_calls' => 0,
                    'cost' => 0,
                ])
                ->has('byKind', 0)
                ->has('byUser', 0),
        );
});

it('renders a byUser breakdown joined to users.name, skipping system-context rows', function (): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), userId: $alice->id);
    seedUsage('briefing', 200, 80, Carbon::parse('2026-05-12'), userId: $alice->id);
    seedUsage('run-insight', 50, 25, Carbon::parse('2026-05-11'), userId: $bob->id);
    seedUsage('briefing', 10, 5, Carbon::parse('2026-05-13')); // user_id null — system call, excluded from per-user breakdown

    $this->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('byUser', 2)
                ->where('byUser.0', [
                    'user_id' => $alice->id,
                    'user_name' => 'Alice',
                    'prompt' => 300,
                    'completion' => 130,
                    'total' => 430,
                    'calls' => 2,
                ])
                ->where('byUser.1', [
                    'user_id' => $bob->id,
                    'user_name' => 'Bob',
                    'prompt' => 50,
                    'completion' => 25,
                    'total' => 75,
                    'calls' => 1,
                ]),
        );
});

it('keeps the user_id in the breakdown after the user is deleted (no FK cascade)', function (): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $aliceId = $alice->id;

    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), userId: $aliceId);
    seedUsage('briefing', 200, 80, Carbon::parse('2026-05-12'), userId: $aliceId);

    $alice->delete();

    $this->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('byUser', 1)
                ->where('byUser.0', [
                    'user_id' => $aliceId,
                    'user_name' => null,
                    'prompt' => 300,
                    'completion' => 130,
                    'total' => 430,
                    'calls' => 2,
                ]),
        );
});

it('surfaces dead-lettered blocks grouped per user', function (): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    deadLetterWeeklyRecap($alice);
    // A second dead-lettered block for the same user (PR context, subject = user).
    $pr = PersonalRecord::factory()->for($alice)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => PersonalRecord::class,
        'subject_id' => $pr->id,
        'analysis_type' => AnalysisType::PrContext,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    $this->get('/ai-usage')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('deadLettered', 1)
                ->where('deadLettered.0.user_id', $alice->id)
                ->where('deadLettered.0.user_name', 'Alice')
                ->where('deadLettered.0.count', 2)
                ->has('deadLettered.0.blocks', 2),
        );
});

it('excludes Done and under-budget Failed blocks from the dead-letter panel', function (): void {
    $user = User::factory()->create();
    // Under-budget Failed (attempts 1) -> still self-healing, not dead-lettered.
    $snap = WeeklySnapshot::factory()->for($user)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
    ]);

    $this->get('/ai-usage')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('deadLettered', 0));
});

it('re-arms and re-dispatches a user\'s dead-lettered blocks on retry', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $row = deadLetterWeeklyRecap($user);

    $this->post("/ai-usage/users/{$user->id}/retry-failed")
        ->assertRedirect();

    $fresh = $row->fresh();
    expect($fresh->attempts)->toBe(0)                          // budget re-armed
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);   // re-dispatched
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('retries a dead-lettered group for a hard-deleted user instead of 404ing', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $userId = $user->id;
    // A user-keyed analysis (subject_id = user id, no FK) survives a hard delete,
    // unlike WeeklyRecap whose WeeklySnapshot subject cascades away.
    $row = Analysis::factory()->failed()->create([
        'subject_type' => AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        'subject_id' => $userId,
        'analysis_type' => AnalysisType::DailyGreeting,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
    $user->delete();

    $this->post("/ai-usage/users/{$userId}/retry-failed")
        ->assertRedirect();

    $fresh = $row->fresh();
    expect($fresh->attempts)->toBe(0)                          // budget re-armed
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);   // re-dispatched
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
});

it('retry is reachable without a Laravel session (edge auth handles access in prod)', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    // No dead-lettered rows: still a clean, session-less redirect (0 retried).
    $this->post("/ai-usage/users/{$user->id}/retry-failed")->assertRedirect();
    Bus::assertNothingDispatched();
});
