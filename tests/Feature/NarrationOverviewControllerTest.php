<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\AI\Analysis;
use App\Models\AI\ContentFilterEvent;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// The /devtools/narration dashboard is gated by HTTP Basic Auth against a
// shared devtools password. Every case below sends the correct credential;
// the authorization cases override it and call armDevtoolsGate() first,
// because the gate only challenges in production.
beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:secret')]);
});

/** The devtools gate only challenges in production; arm it, CSRF and all. */
function armDevtoolsGate(): array
{
    app()->detectEnvironment(fn (): string => 'production');

    return ['_token' => 'devtools-test-token'];
}

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

it('is reachable with the correct devtools password', function (): void {
    armDevtoolsGate();

    $this->get('/devtools/narration')->assertSuccessful();
});

it('challenges a request with no devtools password', function (): void {
    armDevtoolsGate();

    $this->withHeaders(['Authorization' => ''])
        ->get('/devtools/narration')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Devtools"');
});

it('challenges a request with the wrong devtools password', function (): void {
    armDevtoolsGate();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:wrong')])
        ->get('/devtools/narration')
        ->assertUnauthorized();
});

it('renders the narration overview with totals + per-kind breakdown filtered by date', function (): void {
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00:00'), latencyMs: 800);
    seedUsage('briefing', 200, 80, Carbon::parse('2026-05-15 11:00:00'), latencyMs: 1200, truncated: true);
    seedUsage('run-insight', 300, 150, Carbon::parse('2026-05-12 13:00:00'), latencyMs: 2400);
    seedUsage('briefing', 999, 999, Carbon::parse('2026-04-30 23:00:00')); // outside range

    $this->get('/devtools/narration?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Narration/Overview')
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
                    // Seeded without steps, as rows written before the agent
                    // columns existed look: unmeasured, not zero.
                    'avg_steps' => null,
                    'cached_pct' => null,
                    'reasoning_pct' => null,
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
                    'avg_steps' => null,
                    'cached_pct' => null,
                    'reasoning_pct' => null,
                ])
                ->has('byDeployment')
                ->has('budget')
                ->has('contentFilter')
                ->has('chart')
                ->has('athletes')
                ->where('cappedToday', 0)
                ->has('pauseReason'),
        );
});

it('surfaces the content-filter trip count and rate on the overview', function (): void {
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-11'));
    ContentFilterEvent::query()->create(['kind' => 'briefing', 'created_at' => Carbon::parse('2026-05-10')]);

    $this->get('/devtools/narration?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('contentFilter', ['trips' => 1, 'pct' => 50]),
        );
});

it('carries the app-wide ceiling into the budget block the gauge renders', function (): void {
    config([
        'azure_openai.daily_cost_ceiling_total' => 8.5,
        'azure_openai.daily_cost_ceiling_per_user' => 1.25,
    ]);

    $this->get('/devtools/narration')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has(
                    'budget',
                    fn (AssertableInertia $budget) => $budget
                        ->where('totalCeiling', 8.5)
                        ->where('perUserCeiling', 1.25)
                        ->etc(),
                )
                ->etc(),
        );
});

it('defaults to the rolling last 7 days when no range is given', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00'); // 7d window = 2026-05-13 .. now
    seedUsage('inside', 50, 50, Carbon::parse('2026-05-15'));
    seedUsage('outside', 50, 50, Carbon::parse('2026-05-10')); // older than 7 days

    $this->get('/devtools/narration')
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

    $this->get("/devtools/narration?range={$range}")
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
    $this->get('/devtools/narration?from=2026-05-01&to=2026-05-19')
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

    $this->get('/devtools/narration?range=7d')
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('totals.total', 150)
                ->where('previousTotals.total', 60),
        );

    $this->get('/devtools/narration?range=all')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('previousTotals', null));

    Carbon::setTestNow();
});

it('rejects malformed date inputs', function (): void {
    $this->getJson('/devtools/narration?from=yesterday')->assertStatus(422);
});

it('returns zeroed totals and empty breakdown when no rows fall within range', function (): void {
    $this->get('/devtools/narration?from=2026-05-01&to=2026-05-19')
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
                ->has('athletes', 0),
        );
});

it('renders the dashboard past a dead-lettered row of a retired type', function (): void {
    $user = User::factory()->create();
    DB::table('ai_analyses')->insert([
        'subject_type' => 'daily_greeting_user_day',
        'subject_id' => $user->id,
        'analysis_type' => 'daily_greeting',
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Failed->value,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $this->get('/devtools/narration')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('athletes.0.dead_lettered', 0));
});

it('re-arms and re-dispatches a user\'s dead-lettered blocks on retry', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $row = deadLetterWeeklyRecap($user);

    $this->post("/devtools/narration/athletes/{$user->id}/retry-failed")
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
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $userId,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
    $user->delete();

    $this->post("/devtools/narration/athletes/{$userId}/retry-failed")
        ->assertRedirect();

    $fresh = $row->fresh();
    expect($fresh->attempts)->toBe(0)                          // budget re-armed
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);   // re-dispatched
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('retry is reachable with the correct devtools password', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    $token = armDevtoolsGate();

    // No dead-lettered rows: still a clean redirect (0 retried).
    $this->withSession($token)
        ->post("/devtools/narration/athletes/{$user->id}/retry-failed", $token)
        ->assertRedirect();
    Bus::assertNothingDispatched();
});

it('challenges the mutating retry with the wrong devtools password', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    $token = armDevtoolsGate();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:wrong')])
        ->withSession($token)
        ->post("/devtools/narration/athletes/{$user->id}/retry-failed", $token)
        ->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('also re-arms a user\'s under-budget Failed blocks on retry (not only dead-lettered)', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create();
    $underBudget = Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'attempts' => 1,
    ]);

    $this->post("/devtools/narration/athletes/{$user->id}/retry-failed")->assertRedirect();

    $fresh = $underBudget->fresh();
    expect($fresh->attempts)->toBe(0)
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('runs the recover command and flashes a confirmation', function (): void {
    Bus::fake();
    $row = deadLetterWeeklyRecap(User::factory()->create());

    $this->post('/devtools/narration/recover')
        ->assertRedirect()
        ->assertSessionHas('info');

    $fresh = $row->fresh();
    expect($fresh->attempts)->toBe(0)
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('challenges the recover action with the wrong devtools password', function (): void {
    $token = armDevtoolsGate();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('devtools:wrong')])
        ->withSession($token)
        ->post('/devtools/narration/recover', $token)
        ->assertUnauthorized();
});

it('audits a per-user re-arm, naming the athlete and how many blocks it touched', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    deadLetterWeeklyRecap($user);

    $this->post("/devtools/narration/athletes/{$user->id}/retry-failed")->assertRedirect();

    $action = DevtoolsAction::query()->sole();

    expect($action->action)->toBe('narration.retry_failed')
        ->and($action->user_id)->toBe($user->id)
        ->and($action->payload)->toBe(['blocks' => 1])
        ->and($action->actor)->not->toBe('');
});

it('audits the app-wide recovery run', function (): void {
    Bus::fake();
    deadLetterWeeklyRecap(User::factory()->create());

    $this->post('/devtools/narration/recover')->assertRedirect();

    $action = DevtoolsAction::query()->sole();

    expect($action->action)->toBe('narration.recover')
        ->and($action->user_id)->toBeNull();
});

it('permanently redirects the old ai-usage path to the renamed page', function (): void {
    $this->get('/devtools/ai-usage')
        ->assertStatus(301)
        ->assertRedirect('/devtools/narration');
});

it('carries one row per athlete, demo last, with the money and quality columns', function (): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $demo = User::factory()->create(['name' => 'Demo', 'is_demo' => true]);
    seedUsage('briefing', 100, 50, Carbon::now(), userId: $alice->id);

    $this->get('/devtools/narration')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('athletes', 2)
                ->where('athletes.0.user_id', $alice->id)
                ->where('athletes.0.calls', 1)
                ->where('athletes.1.user_id', $demo->id)
                ->where('athletes.1.is_demo', true)
                ->has('athletes.0.sparkline')
                ->has('athletes.0.served'),
        );
});

it('counts the athletes already capped today for the header strip', function (): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', 0.0000001);
    config()->set('azure_openai.prices', ['gpt-test' => ['input_per_1m' => 2.5, 'output_per_1m' => 10.0]]);
    $alice = User::factory()->create();
    seedUsage('briefing', 100_000, 0, Carbon::now(), userId: $alice->id);

    $this->get('/devtools/narration')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cappedToday', 1));
});

it('narrows the cost chart to the athlete the filter names', function (): void {
    config()->set('azure_openai.prices', ['gpt-test' => ['input_per_1m' => 2.5, 'output_per_1m' => 10.0]]);
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    seedUsage('briefing', 1_000_000, 0, Carbon::now(), userId: $alice->id);
    seedUsage('briefing', 1_000_000, 0, Carbon::now(), userId: $bob->id);

    $this->get("/devtools/narration?athlete={$alice->id}")
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('athlete', $alice->id)
                ->where('chart.days.0.cost', 2.5),
        );
});

it('rejects a non-numeric athlete filter', function (): void {
    $this->getJson('/devtools/narration?athlete=alice')->assertStatus(422);
});
