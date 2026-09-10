<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\AI\ContentFilterEvent;
use App\Models\Feedback;
use App\Models\AI\TokenUsage;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\User\UserEraser;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\ServedBy;
use App\Services\AI\TokenUsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Seed one instrumented call, i.e. a row written after the agent columns landed. */
function seedAgentUsage(string $kind, int $prompt, int $completion, int $cached, int $reasoning, int $steps): void
{
    TokenUsage::query()->create([
        'kind' => $kind,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'cached_tokens' => $cached,
        'reasoning_tokens' => $reasoning,
        'steps' => $steps,
        'model' => 'gpt-4o',
        'created_at' => Carbon::today(),
    ]);
}

beforeEach(function (): void {
    // Freeze time so a seed at Carbon::today() and the report's "today" query
    // can't straddle the Asia/Jakarta midnight boundary and flake the todayCost.
    $this->freezeTime();

    config()->set('azure_openai.daily_cost_ceiling_per_user', null);
    config()->set('azure_openai.daily_cost_ceiling_total', null);

    // Deterministic manual rates: gpt-4o = 2.50 in / 10.00 out, gpt-4o-mini =
    // 0.15 / 0.60 (per 1M).
    config()->set('azure_openai.prices', [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00],
        'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60],
    ]);
    $this->report = app(TokenUsageReport::class);
});

function seedReportUsage(
    string $kind,
    int $prompt,
    int $completion,
    Carbon $when,
    ?int $latencyMs = null,
    bool $truncated = false,
    ?int $userId = null,
    string $model = 'gpt-4o',
    AnalysisOrigin $origin = AnalysisOrigin::Unknown,
): void {
    TokenUsage::query()->create([
        'user_id' => $userId,
        'kind' => $kind,
        'origin' => $origin,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'model' => $model,
        'latency_ms' => $latencyMs,
        'truncated' => $truncated,
        'created_at' => $when,
    ]);
}

$range = fn (): array => [Carbon::parse('2026-05-01')->startOfDay(), Carbon::parse('2026-05-19')->endOfDay()];

it('aggregates totals + per-kind ordered by total descending, excluding out-of-range rows', function () use ($range): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00:00'), latencyMs: 800);
    seedReportUsage('briefing', 200, 80, Carbon::parse('2026-05-15 11:00:00'), latencyMs: 1200, truncated: true);
    seedReportUsage('run-insight', 300, 150, Carbon::parse('2026-05-12 13:00:00'), latencyMs: 2400);
    seedReportUsage('briefing', 999, 999, Carbon::parse('2026-04-30 23:00:00')); // out of range

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['totals'])->toMatchArray([
        'prompt' => 600,
        'completion' => 280,
        'total' => 880,
        'calls' => 3,
        'truncated_calls' => 1,
    ])
        // 600 in @ 2.50/1M + 280 out @ 10.00/1M = 0.0015 + 0.0028 = 0.0043
        ->and($result['totals']['cost'])->toEqualWithDelta(0.0043, 1e-9)
        ->and($result['byKind'])->toHaveCount(2)
        ->and($result['byKind'][0])->toMatchArray([
            'kind' => 'run-insight',
            'prompt' => 300,
            'completion' => 150,
            'total' => 450,
            'calls' => 1,
            'truncated_calls' => 0,
            'avg_latency_ms' => 2400,
            'max_latency_ms' => 2400,
        ])
        // run-insight: 300 in @ 2.50/1M + 150 out @ 10.00/1M = 0.00075 + 0.0015 = 0.00225
        ->and($result['byKind'][0]['cost'])->toBe(0.00225)
        ->and($result['byKind'][1])->toMatchArray([
            'kind' => 'briefing',
            'avg_latency_ms' => 1000,
            'max_latency_ms' => 1200,
        ]);
});

it('computes a kind-level cost and latency rolled up across multiple deployments', function () use ($range): void {
    // Same kind, two deployments: costs and the call-weighted latency average roll up.
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-10'), latencyMs: 1000, model: 'gpt-4o');
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-11'), latencyMs: 2000, model: 'gpt-4o-mini');

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['byKind'])->toHaveCount(1)
        // 1M in @ 2.50 (gpt-4o) + 1M in @ 0.15 (gpt-4o-mini) = 2.65
        ->and($result['byKind'][0]['cost'])->toBe(2.65)
        // Call-weighted average of two single-call subgroups: (1000 + 2000) / 2.
        ->and($result['byKind'][0]['avg_latency_ms'])->toBe(1500)
        ->and($result['byKind'][0]['max_latency_ms'])->toBe(2000);
});

it('breaks usage down by deployment with per-deployment cost', function () use ($range): void {
    seedReportUsage('briefing', 1_000_000, 1_000_000, Carbon::parse('2026-05-10'), model: 'gpt-4o');
    seedReportUsage('run-insight', 2_000_000, 0, Carbon::parse('2026-05-11'), model: 'gpt-4o-mini');

    [$from, $to] = $range();
    $byDeployment = collect($this->report->build($from, $to, null)['byDeployment'])->keyBy('deployment');

    expect($byDeployment->get('gpt-4o'))->toMatchArray([
        'deployment' => 'gpt-4o',
        'prompt' => 1_000_000,
        'completion' => 1_000_000,
        'total' => 2_000_000,
        'calls' => 1,
    ])
        // 1M in @ 2.50 + 1M out @ 10.00 = 12.50
        ->and($byDeployment->get('gpt-4o')['cost'])->toBe(12.50)
        // gpt-4o-mini: 2M in @ 0.15/1M = 0.30
        ->and($byDeployment->get('gpt-4o-mini')['cost'])->toBe(0.30);
});

it('reports the budget block with null ceiling and the config currency by default', function () use ($range): void {
    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['budget'])->toMatchArray([
        'todayCost' => 0.0,
        'dailyCeiling' => null,
        'currency' => 'USD',
    ]);
});

it('reports total spend against a combined ceiling derived from the per-athlete one', function () use ($range): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', 5.0);
    User::factory()->count(3)->create();
    User::factory()->create(['is_demo' => true]); // spends nothing, so it is not counted
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), model: 'gpt-4o'); // 2.50 today

    [$from, $to] = [Carbon::today()->subDay(), Carbon::today()->addDay()];
    $result = $this->report->build($from, $to, null);

    // Nothing enforces the combined figure — it is the sum of what each athlete
    // may individually spend, reported so the total bill stays visible.
    expect($result['budget']['perUserCeiling'])->toBe(5.0)
        ->and($result['budget']['athletes'])->toBe(3)
        ->and($result['budget']['dailyCeiling'])->toBe(15.0)
        ->and($result['budget']['todayCost'])->toBe(2.50);
});

it('reports no combined ceiling when none is configured', function () use ($range): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', null);

    $result = $this->report->build(Carbon::today()->subDay(), Carbon::today()->addDay(), null);

    expect($result['budget']['dailyCeiling'])->toBeNull()
        ->and($result['budget']['perUserCeiling'])->toBeNull();
});

it('reports the enforced app-wide ceiling alongside the derived combined figure', function (): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', 5.0);
    config()->set('azure_openai.daily_cost_ceiling_total', 8.0);
    User::factory()->count(3)->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), model: 'gpt-4o'); // 2.50 today

    $result = $this->report->build(Carbon::today()->subDay(), Carbon::today()->addDay(), null);

    expect($result['budget']['totalCeiling'])->toBe(8.0)
        ->and($result['budget']['dailyCeiling'])->toBe(15.0)
        ->and($result['budget']['todayCost'])->toBe(2.50);
});

it('reports no app-wide ceiling when none is configured', function (): void {
    $result = $this->report->build(Carbon::today()->subDay(), Carbon::today()->addDay(), null);

    expect($result['budget']['totalCeiling'])->toBeNull();
});

it('carries the ceiling trip and the rule-based fill count into the budget block', function () use ($range): void {
    $ledger = app(CostCeilingLedger::class);
    $ledger->recordTrip();
    $ledger->recordDegradedFill();
    $ledger->recordDegradedFill();

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['budget']['trippedAt'])->toBe(Carbon::now()->toIso8601String())
        ->and($result['budget']['degradedFills'])->toBe(2);
});


it('narrows every figure to one kind when the filter names it', function () use ($range): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedReportUsage('run-insight', 300, 150, Carbon::parse('2026-05-10'));

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, 'briefing');

    expect($result['byKind'])->toHaveCount(1)
        ->and($result['byKind'][0]['kind'])->toBe('briefing')
        ->and($result['totals']['total'])->toBe(150);
});

it('sums per-day cost across deployments in the stacked series', function () use ($range): void {
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-10'), model: 'gpt-4o');         // 2.50
    seedReportUsage('run-insight', 1_000_000, 0, Carbon::parse('2026-05-10'), model: 'gpt-4o-mini'); // 0.15

    [$from, $to] = $range();
    $chart = $this->report->dailyCostByKind($from, $to);

    expect($chart['days'])->toHaveCount(1)
        ->and($chart['days'][0]['day'])->toBe('2026-05-10')
        ->and($chart['days'][0]['cost'])->toBe(2.65)
        ->and($chart['days'][0]['byKind']['briefing'])->toBe(2.50)
        ->and($chart['days'][0]['byKind']['run-insight'])->toBe(0.15);
});

it('orders the stack by kind spend, heaviest band first, and labels it', function () use ($range): void {
    seedReportUsage('card_flavor', 10, 5, Carbon::parse('2026-05-10'));
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-11'));

    [$from, $to] = $range();
    $chart = $this->report->dailyCostByKind($from, $to);

    expect(array_column($chart['kinds'], 'kind'))->toBe(['briefing', 'card_flavor'])
        ->and($chart['kinds'][1]['label'])->toBe('CardFlavor');
});

it('narrows the stacked series to one athlete when the chart filter names one', function () use ($range): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-10'), userId: $alice->id);
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-10'), userId: $bob->id);

    [$from, $to] = $range();

    expect($this->report->dailyCostByKind($from, $to, $alice->id)['days'][0]['cost'])->toBe(2.50);
});

it('returns an empty stacked series when nothing billed in the range', function () use ($range): void {
    [$from, $to] = $range();

    expect($this->report->dailyCostByKind($from, $to))->toBe(['kinds' => [], 'days' => []]);
});

it('labels available kinds via AnalysisType, falling back to the raw value', function () use ($range): void {
    seedReportUsage('card_flavor', 10, 5, Carbon::parse('2026-05-10'));
    seedReportUsage('totally-unknown-kind', 10, 5, Carbon::parse('2026-05-11'));

    [$from, $to] = $range();
    $kinds = collect($this->report->build($from, $to, null)['availableKinds'])->keyBy('value');

    // A known kind resolves to the AnalysisType case name; unknown stays raw.
    expect($kinds->get('card_flavor')['label'])->toBe('CardFlavor')
        ->and($kinds->get('totally-unknown-kind')['label'])->toBe('totally-unknown-kind');
});

it('sums previousTotals over the equal-length window immediately before the range', function (): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-12'), model: 'gpt-4o');      // inside range
    seedReportUsage('briefing', 1_000_000, 0, Carbon::parse('2026-05-05'), model: 'gpt-4o'); // prior window
    seedReportUsage('briefing', 999, 999, Carbon::parse('2026-04-01'));                        // older than prior window

    $from = Carbon::parse('2026-05-10')->startOfDay();
    $to = Carbon::parse('2026-05-19')->endOfDay();
    $result = $this->report->build($from, $to, null);

    expect($result['previousTotals'])->toMatchArray(['prompt' => 1_000_000, 'total' => 1_000_000, 'calls' => 1])
        // 1M in @ 2.50/1M = 2.50
        ->and($result['previousTotals']['cost'])->toBe(2.50);
});

it('scopes previousTotals to the kind filter', function (): void {
    seedReportUsage('briefing', 100, 0, Carbon::parse('2026-05-05'));
    seedReportUsage('run-insight', 200, 0, Carbon::parse('2026-05-05'));

    $from = Carbon::parse('2026-05-10')->startOfDay();
    $to = Carbon::parse('2026-05-19')->endOfDay();

    expect($this->report->build($from, $to, 'briefing')['previousTotals']['prompt'])->toBe(100);
});

it('omits previousTotals when includePrevious is false', function (): void {
    $from = Carbon::parse('2026-05-10')->startOfDay();
    $to = Carbon::parse('2026-05-19')->endOfDay();

    expect($this->report->build($from, $to, null, includePrevious: false)['previousTotals'])->toBeNull();
});

it('returns zeroed totals and empty breakdowns when no rows fall in range', function () use ($range): void {
    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['totals'])->toBe([
        'prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0, 'cost' => 0.0,
    ])
        ->and($result['byKind'])->toBe([])
        ->and($result['byDeployment'])->toBe([])
        ->and($result['availableKinds'])->toBe([]);
});

it('summarises how a kind behaves as an agent', function (): void {
    // Two calls: one two-turn, one four-turn, so the average is not an integer.
    seedAgentUsage('run_insight', 1000, 200, 600, 50, 2);
    seedAgentUsage('run_insight', 1000, 200, 900, 30, 4);

    $row = collect($this->report->build(Carbon::today(), Carbon::today(), null)['byKind'])
        ->firstWhere('kind', 'run_insight');

    expect($row['avg_steps'])->toBe(3.0)
        ->and($row['cached_pct'])->toBe(75.0)
        ->and($row['reasoning_pct'])->toBe(20.0);
});

// A kind whose rows all predate the agent columns must read as unmeasured, not
// as a narrator that never cached and never reasoned.
it('leaves the agent summary null when no call recorded a step', function (): void {
    seedReportUsage('briefing', 100, 50, Carbon::today());

    $row = collect($this->report->build(Carbon::today(), Carbon::today(), null)['byKind'])
        ->firstWhere('kind', 'briefing');

    expect($row['avg_steps'])->toBeNull()
        ->and($row['cached_pct'])->toBeNull()
        ->and($row['reasoning_pct'])->toBeNull();
});

it('splits spend by what started the call, which kind alone cannot express', function () use ($range): void {
    // Same narrator, three different triggers: this is exactly the question the
    // kind breakdown cannot answer.
    seedReportUsage('run_insight', 100, 50, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::Ingest);
    seedReportUsage('run_insight', 200, 60, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Ingest);
    seedReportUsage('run_insight', 40, 10, Carbon::parse('2026-05-12'), origin: AnalysisOrigin::User);
    seedReportUsage('weekly_recap', 10, 5, Carbon::parse('2026-05-12'), origin: AnalysisOrigin::Recovery);

    [$from, $to] = $range();
    $byOrigin = collect($this->report->build($from, $to, null)['byOrigin'])->keyBy('origin');

    expect($byOrigin->get('ingest')['calls'])->toBe(2)
        ->and($byOrigin->get('ingest')['total'])->toBe(410)
        ->and($byOrigin->get('ingest')['label'])->toBe('Ingest cascade')
        ->and($byOrigin->get('user')['calls'])->toBe(1)
        ->and($byOrigin->get('recovery')['calls'])->toBe(1);
});

it('orders the origin breakdown by spend, heaviest first', function () use ($range): void {
    seedReportUsage('run_insight', 10, 5, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::User);
    seedReportUsage('run_insight', 900, 300, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Ingest);

    [$from, $to] = $range();

    expect(array_column($this->report->build($from, $to, null)['byOrigin'], 'origin'))
        ->toBe(['ingest', 'user']);
});

it('narrows every figure to one origin when the filter names it', function () use ($range): void {
    seedReportUsage('run_insight', 100, 50, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::Ingest);
    seedReportUsage('run_insight', 900, 300, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Scheduled);

    [$from, $to] = $range();
    $report = $this->report->build($from, $to, null, origin: 'ingest');

    expect($report['totals']['calls'])->toBe(1)
        ->and($report['totals']['total'])->toBe(150)
        ->and($report['byOrigin'])->toHaveCount(1);
});

it('offers only the origins actually present in the range', function () use ($range): void {
    seedReportUsage('run_insight', 10, 5, Carbon::parse('2026-05-10'), origin: AnalysisOrigin::Ingest);
    seedReportUsage('weekly_recap', 10, 5, Carbon::parse('2026-05-11'), origin: AnalysisOrigin::Recovery);

    [$from, $to] = $range();

    expect($this->report->build($from, $to, null)['availableOrigins'])->toBe([
        ['value' => 'ingest', 'label' => 'Ingest cascade'],
        ['value' => 'recovery', 'label' => 'Recovery'],
    ]);
});

it('reports the content-filter trip count and its share of calls in range', function () use ($range): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-11'));
    ContentFilterEvent::query()->create(['kind' => 'briefing', 'created_at' => Carbon::parse('2026-05-10')]);
    ContentFilterEvent::query()->create(['kind' => 'briefing', 'created_at' => Carbon::parse('2026-04-01')]); // out of range

    [$from, $to] = $range();

    expect($this->report->build($from, $to, null)['contentFilter'])->toBe([
        'trips' => 1,
        'pct' => 50.0,
    ]);
});

it('reports a null content-filter share when the range has no calls', function () use ($range): void {
    [$from, $to] = $range();

    expect($this->report->build($from, $to, null)['contentFilter'])->toBe([
        'trips' => 0,
        'pct' => null,
    ]);
});

/** A Done narration owned by $userId, narrated inside the report's range. */
function seedDoneNarration(int $userId, ?ServedBy $servedBy, Carbon $when): void
{
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $userId,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => $when->toDateString(),
        'status' => AnalysisStatus::Done,
        'content' => 'narrated',
        'served_by' => $servedBy,
        'generated_at' => $when,
    ]);
}

it('measures the money columns over fixed windows, whatever range is selected', function (): void {
    $alice = User::factory()->create();

    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $alice->id);            // 2.50
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today()->subDays(3), userId: $alice->id); // 2.50
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today()->subDays(20), userId: $alice->id); // 2.50
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today()->subDays(60), userId: $alice->id); // outside

    $row = collect($this->report->athletes(Carbon::parse('2026-05-10'), Carbon::parse('2026-05-19')))
        ->firstWhere('user_id', $alice->id);

    expect($row['today'])->toBe(2.50)
        ->and($row['last7'])->toBe(5.00)
        ->and($row['last30'])->toBe(7.50)
        ->and($row['calls'])->toBe(3);
});

it('gives the sparkline one slot per day of the window, silent days included', function (): void {
    $alice = User::factory()->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $alice->id);

    $row = collect($this->report->athletes(Carbon::today(), Carbon::now()))
        ->firstWhere('user_id', $alice->id);

    expect($row['sparkline'])->toHaveCount(TokenUsageReport::ATHLETE_WINDOW_DAYS)
        ->and($row['sparkline'][0]['cost'])->toBe(0.0)
        ->and(end($row['sparkline'])['cost'])->toBe(2.50);
});

it('marks an athlete capped once today reaches their ceiling', function (): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', 2.0);
    $alice = User::factory()->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $alice->id); // 2.50

    $row = collect($this->report->athletes(Carbon::today(), Carbon::now()))
        ->firstWhere('user_id', $alice->id);

    expect($row['ceiling'])->toBe(2.0)
        ->and($row['capped'])->toBeTrue()
        ->and($row['ceiling_overridden'])->toBeFalse();
});

it('reads a today-only override in place of the configured ceiling', function (): void {
    config()->set('azure_openai.daily_cost_ceiling_per_user', 2.0);
    $alice = User::factory()->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $alice->id); // 2.50
    app(CeilingOverride::class)->set($alice->id, 10.0);

    $row = collect($this->report->athletes(Carbon::today(), Carbon::now()))
        ->firstWhere('user_id', $alice->id);

    expect($row['ceiling'])->toBe(10.0)
        ->and($row['ceiling_overridden'])->toBeTrue()
        ->and($row['capped'])->toBeFalse();
});

it('never caps an athlete when no ceiling is configured at all', function (): void {
    $alice = User::factory()->create();
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $alice->id);

    $row = collect($this->report->athletes(Carbon::today(), Carbon::now()))
        ->firstWhere('user_id', $alice->id);

    expect($row['ceiling'])->toBeNull()
        ->and($row['capped'])->toBeFalse();
});

it('splits done narration by its producer, counting a null served_by as unknown', function () use ($range): void {
    $alice = User::factory()->create();
    seedDoneNarration($alice->id, ServedBy::Llm, Carbon::parse('2026-05-11'));
    seedDoneNarration($alice->id, ServedBy::Llm, Carbon::parse('2026-05-12'));
    seedDoneNarration($alice->id, ServedBy::RuleBased, Carbon::parse('2026-05-13'));
    seedDoneNarration($alice->id, null, Carbon::parse('2026-05-14'));
    seedDoneNarration($alice->id, ServedBy::Llm, Carbon::parse('2026-04-01')); // outside the range

    [$from, $to] = $range();
    $row = collect($this->report->athletes($from, $to))->firstWhere('user_id', $alice->id);

    expect($row['served'])->toBe(['llm' => 2, 'rule_based' => 1, 'unknown' => 1]);
});

it('counts the flags an athlete filed in range', function () use ($range): void {
    $alice = User::factory()->create();
    Feedback::factory()->for($alice)->create(['subject_id' => 1, 'created_at' => Carbon::parse('2026-05-11')]);
    Feedback::factory()->for($alice)->create(['subject_id' => 2, 'created_at' => Carbon::parse('2026-05-12')]);
    Feedback::factory()->for($alice)->create(['subject_id' => 3, 'created_at' => Carbon::parse('2026-04-01')]);

    [$from, $to] = $range();
    $row = collect($this->report->athletes($from, $to))->firstWhere('user_id', $alice->id);

    expect($row['flags'])->toBe(2);
});

it('counts dead-lettered blocks regardless of the range, since they still need an action', function () use ($range): void {
    $alice = User::factory()->create();
    Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $alice->id,
        'status' => AnalysisStatus::Failed,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        'updated_at' => Carbon::parse('2026-01-01'),
    ]);

    [$from, $to] = $range();
    $row = collect($this->report->athletes($from, $to))->firstWhere('user_id', $alice->id);

    expect($row['dead_lettered'])->toBe(1);
});

it('lists a silent athlete rather than hiding them behind zero spend', function () use ($range): void {
    $alice = User::factory()->create();

    [$from, $to] = $range();
    $row = collect($this->report->athletes($from, $to))->firstWhere('user_id', $alice->id);

    expect($row['today'])->toBe(0.0)
        ->and($row['calls'])->toBe(0)
        ->and($row['sparkline'])->toHaveCount(TokenUsageReport::ATHLETE_WINDOW_DAYS);
});

it('sorts the demo account last and the rest by 30-day spend', function () use ($range): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $quiet = User::factory()->create();
    $heavy = User::factory()->create();

    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $demo->id);
    seedReportUsage('briefing', 1_000_000, 0, Carbon::today(), userId: $heavy->id);
    seedReportUsage('briefing', 10, 0, Carbon::today(), userId: $quiet->id);

    [$from, $to] = $range();

    expect(array_column($this->report->athletes($from, $to), 'user_id'))
        ->toBe([$heavy->id, $quiet->id, $demo->id]);
});

it('keeps a deleted account listed under the snapshot the eraser left behind', function () use ($range): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $aliceId = $alice->id;
    StravaConnection::factory()->for($alice)->create(['strava_athlete_id' => 909090]);
    seedReportUsage('briefing', 100, 50, Carbon::today(), userId: $aliceId);

    app(UserEraser::class)->erase($alice);

    [$from, $to] = $range();
    $row = collect($this->report->athletes($from, $to))->firstWhere('user_id', $aliceId);

    expect($row['user_name'])->toBe('Alice')
        ->and($row['deleted'])->toBeTrue();
});
