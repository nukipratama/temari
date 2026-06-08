<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\TokenUsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->report = new TokenUsageReport();
});

function seedReportUsage(
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

$range = fn (): array => [Carbon::parse('2026-05-01')->startOfDay(), Carbon::parse('2026-05-19')->endOfDay()];

it('aggregates totals + per-kind ordered by total descending, excluding out-of-range rows', function () use ($range): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00:00'), latencyMs: 800);
    seedReportUsage('briefing', 200, 80, Carbon::parse('2026-05-15 11:00:00'), latencyMs: 1200, truncated: true);
    seedReportUsage('run-insight', 300, 150, Carbon::parse('2026-05-12 13:00:00'), latencyMs: 2400);
    seedReportUsage('briefing', 999, 999, Carbon::parse('2026-04-30 23:00:00')); // out of range

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['totals'])->toBe([
        'prompt' => 600,
        'completion' => 280,
        'total' => 880,
        'calls' => 3,
        'truncated_calls' => 1,
    ])
        ->and($result['byKind'])->toHaveCount(2)
        ->and($result['byKind'][0])->toBe([
            'kind' => 'run-insight',
            'prompt' => 300,
            'completion' => 150,
            'total' => 450,
            'calls' => 1,
            'truncated_calls' => 0,
            'avg_latency_ms' => 2400,
            'max_latency_ms' => 2400,
        ])
        ->and($result['byKind'][1])->toMatchArray([
            'kind' => 'briefing',
            'avg_latency_ms' => 1000,
            'max_latency_ms' => 1200,
        ]);
});

it('filters by kind while leaving the daily series unfiltered', function () use ($range): void {
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10'));
    seedReportUsage('run-insight', 300, 150, Carbon::parse('2026-05-10'));

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, 'briefing');

    expect($result['byKind'])->toHaveCount(1)
        ->and($result['byKind'][0]['kind'])->toBe('briefing')
        ->and($result['totals']['total'])->toBe(150)
        // daily ignores the kind filter so it still sums both kinds.
        ->and($result['daily'][0])->toMatchArray(['day' => '2026-05-10', 'total' => 600]);
});

it('stitches user names from the app schema and skips system (null user_id) rows', function () use ($range): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), userId: $alice->id);
    seedReportUsage('briefing', 200, 80, Carbon::parse('2026-05-12'), userId: $alice->id);
    seedReportUsage('run-insight', 50, 25, Carbon::parse('2026-05-11'), userId: $bob->id);
    seedReportUsage('briefing', 10, 5, Carbon::parse('2026-05-13')); // null user_id, excluded

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['byUser'])->toHaveCount(2)
        ->and($result['byUser'][0])->toBe([
            'user_id' => $alice->id,
            'user_name' => 'Alice',
            'prompt' => 300,
            'completion' => 130,
            'total' => 430,
            'calls' => 2,
        ])
        ->and($result['byUser'][1]['user_name'])->toBe('Bob');
});

it('keeps the user_id with a null name when the user no longer exists', function () use ($range): void {
    $alice = User::factory()->create(['name' => 'Alice']);
    $aliceId = $alice->id;
    seedReportUsage('briefing', 100, 50, Carbon::parse('2026-05-10'), userId: $aliceId);
    $alice->delete();

    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['byUser'][0])->toMatchArray(['user_id' => $aliceId, 'user_name' => null]);
});

it('labels available kinds via AnalysisType, falling back to the raw value', function () use ($range): void {
    seedReportUsage('pr_context', 10, 5, Carbon::parse('2026-05-10'));
    seedReportUsage('totally-unknown-kind', 10, 5, Carbon::parse('2026-05-11'));

    [$from, $to] = $range();
    $kinds = collect($this->report->build($from, $to, null)['availableKinds'])->keyBy('value');

    // A known kind resolves to the AnalysisType case name; unknown stays raw.
    expect($kinds->get('pr_context')['label'])->toBe('PrContext')
        ->and($kinds->get('totally-unknown-kind')['label'])->toBe('totally-unknown-kind');
});

it('returns zeroed totals and empty breakdowns when no rows fall in range', function () use ($range): void {
    [$from, $to] = $range();
    $result = $this->report->build($from, $to, null);

    expect($result['totals'])->toBe([
        'prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0,
    ])
        ->and($result['byKind'])->toBe([])
        ->and($result['byUser'])->toBe([])
        ->and($result['daily'])->toBe([])
        ->and($result['availableKinds'])->toBe([]);
});
