<?php

declare(strict_types=1);

use App\Jobs\AI\AnswerRunQuestionJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\RunQuestion;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\RunQuestion\RunQuestionTopic;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/');
    config()->set('azure_openai.api_key', 'fake-key');
});

function runFor(User $user, array $detail = []): Activity
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8000.0,
        'moving_time' => 2400,
        'weather_temp_c' => 26,
        'stream_summary' => null,
        ...$detail,
    ]);

    return $activity;
}

it('requires authentication', function (): void {
    $this->postJson('/api/activities/1/questions', ['question' => 'why did my HR drift?'])
        ->assertStatus(401);
});

it('queues the question and hands back the pending exchange', function (): void {
    $user = User::factory()->create();
    $activity = runFor($user);

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'why did my HR drift?'])
        ->assertCreated()
        ->assertJson([
            'activity_id' => $activity->id,
            'question' => 'why did my HR drift?',
            'answer' => null,
            'status' => 'queued',
        ]);

    Bus::assertDispatched(AnswerRunQuestionJob::class);
    expect(RunQuestion::query()->sole()->user_id)->toBe($user->id);
});

it('trims the question before storing it', function (): void {
    $user = User::factory()->create();
    $activity = runFor($user);

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => '  why did my HR drift?  '])
        ->assertCreated()
        ->assertJson(['question' => 'why did my HR drift?']);

    expect(RunQuestion::query()->sole()->question)->toBe('why did my HR drift?');
});

it('rejects a question with no substance', function (): void {
    $user = User::factory()->create();
    $activity = runFor($user);

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'hm'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('question');

    Bus::assertNothingDispatched();
});

// ── Scoping ─────────────────────────────────────────────────────────────────

it('refuses a question about someone else run', function (): void {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $theirRun = runFor($them);

    $this->actingAs($me)
        ->postJson("/api/activities/{$theirRun->id}/questions", ['question' => 'how fast was this?'])
        ->assertForbidden();

    expect(RunQuestion::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('refuses to read someone else question thread', function (): void {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $theirRun = runFor($them);
    RunQuestion::factory()->answered()->create(['user_id' => $them->id, 'activity_id' => $theirRun->id]);

    $this->actingAs($me)
        ->getJson("/api/activities/{$theirRun->id}/questions")
        ->assertForbidden();
});

it('returns only this run thread, never another run of the same user', function (): void {
    $user = User::factory()->create();
    $thisRun = runFor($user);
    $thatRun = runFor($user);

    RunQuestion::factory()->answered('about this one')->create(['user_id' => $user->id, 'activity_id' => $thisRun->id]);
    RunQuestion::factory()->answered('about that one')->create(['user_id' => $user->id, 'activity_id' => $thatRun->id]);

    $response = $this->actingAs($user)->getJson("/api/activities/{$thisRun->id}/questions")->assertOk();

    expect($response->json('questions'))->toHaveCount(1)
        ->and($response->json('questions.0.answer'))->toBe('about this one');
});

// ── Suggestions come off the run own data ───────────────────────────────────

it('suggests only questions this run data can answer', function (): void {
    $user = User::factory()->create();
    $activity = runFor($user, ['stream_summary' => ['hr_drift_bpm' => 7.2]]);

    $suggestions = $this->actingAs($user)
        ->getJson("/api/activities/{$activity->id}/questions")
        ->assertOk()
        ->json('suggestions');

    expect($suggestions)->toContain(RunQuestionTopic::HrDrift->question())
        ->and($suggestions)->not->toContain(RunQuestionTopic::Heat->question());
});

it('falls back to the comparison question on a summary-state run', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['stream_summary' => null, 'weather_temp_c' => 24]);

    expect($this->actingAs($user)->getJson("/api/activities/{$activity->id}/questions")->json('suggestions'))
        ->toBe([RunQuestionTopic::Baseline->question()]);
});

// ── Demo ────────────────────────────────────────────────────────────────────

it('answers the demo account from the run own numbers, dispatching nothing', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $activity = runFor($demo, ['stream_summary' => ['hr_drift_bpm' => 6.4]]);

    $this->actingAs($demo)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => RunQuestionTopic::HrDrift->question()])
        ->assertCreated()
        ->assertJson(['status' => 'done'])
        ->assertJsonPath('answer', fn (?string $answer): bool => is_string($answer) && str_contains($answer, '6.4 bpm'));

    Bus::assertNothingDispatched();
    expect(TokenUsage::query()->count())->toBe(0)
        ->and(RunQuestion::query()->sole()->status)->toBe(AnalysisStatus::Done);
});

it('answers demo free text without dispatching either', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $activity = runFor($demo);

    $this->actingAs($demo)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'should I race a marathon?'])
        ->assertCreated()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
    expect(TokenUsage::query()->count())->toBe(0);
});

it('serves the demo even while generation is paused, since it never bills', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $demo = User::factory()->create(['is_demo' => true]);
    $activity = runFor($demo);

    $this->actingAs($demo)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'how did this one go?'])
        ->assertCreated();

    Bus::assertNothingDispatched();
});

// ── Pause + rate limit ──────────────────────────────────────────────────────

it('answers a real question rule-based when only the cost ceiling stops it', function (): void {
    config([
        'azure_openai.daily_cost_ceiling' => 1.0,
        'azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]],
    ]);
    TokenUsage::query()->create([
        'kind' => 'run_question', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);
    $user = User::factory()->create();
    $activity = runFor($user, ['stream_summary' => ['hr_drift_bpm' => 6.4]]);

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => RunQuestionTopic::HrDrift->question()])
        ->assertCreated()
        ->assertJson(['status' => 'done'])
        ->assertJsonPath('answer', fn (?string $answer): bool => is_string($answer) && str_contains($answer, '6.4 bpm'));

    Bus::assertNothingDispatched();
    expect(RunQuestion::query()->sole()->status)->toBe(AnalysisStatus::Done)
        ->and(app(CostCeilingLedger::class)->today()['degradedFills'])->toBe(1);
});

it('turns a real question away with 409 while generation is paused', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $user = User::factory()->create();
    $activity = runFor($user);

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'how did this one go?'])
        ->assertStatus(409)
        ->assertJson(['error' => 'generation_paused']);

    expect(RunQuestion::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('returns 429 once the per-user question rate is spent', function (): void {
    config()->set('ai.run_question_rate_limit_per_minute', 2);
    RateLimiter::clear('run-question');
    $user = User::factory()->create();
    $activity = runFor($user);

    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($user)
            ->postJson("/api/activities/{$activity->id}/questions", ['question' => "question {$i}?"])
            ->assertCreated();
    }

    $this->actingAs($user)
        ->postJson("/api/activities/{$activity->id}/questions", ['question' => 'one too many?'])
        ->assertStatus(429);

    expect(RunQuestion::query()->count())->toBe(2);
});

it('keeps one user question rate off another user', function (): void {
    config()->set('ai.run_question_rate_limit_per_minute', 1);
    $a = User::factory()->create();
    $b = User::factory()->create();
    $runA = runFor($a);
    $runB = runFor($b);

    $this->actingAs($a)->postJson("/api/activities/{$runA->id}/questions", ['question' => 'first one?'])->assertCreated();
    $this->actingAs($a)->postJson("/api/activities/{$runA->id}/questions", ['question' => 'second one?'])->assertStatus(429);

    $this->actingAs($b)->postJson("/api/activities/{$runB->id}/questions", ['question' => 'my own?'])->assertCreated();
});

it('leaves reading the thread unthrottled', function (): void {
    config()->set('ai.run_question_rate_limit_per_minute', 1);
    $user = User::factory()->create();
    $activity = runFor($user);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)->getJson("/api/activities/{$activity->id}/questions")->assertOk();
    }
});
