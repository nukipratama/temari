<?php

declare(strict_types=1);

use App\Actions\Run\Metrics\ResolveRecentStreamSummariesAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function streamRun(User $user, string $startedAt, ?array $summary): void
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startedAt),
        'stream_summary' => $summary,
    ]);
}

it('returns the summaries of the athlete\'s runs on or after the start of the window', function (): void {
    $user = User::factory()->create();
    streamRun($user, '2026-05-31 06:00:00', ['best_5min_pace' => '4:30']);
    streamRun($user, '2026-05-10 00:00:00', ['best_5min_pace' => '4:40']);
    streamRun($user, '2026-05-09 23:59:59', ['best_5min_pace' => '4:50']);
    streamRun(User::factory()->create(), '2026-05-31 06:00:00', ['best_5min_pace' => '3:00']);

    $paces = collect((new ResolveRecentStreamSummariesAction())($user, Carbon::parse('2026-06-01'), 22))
        ->map(fn ($summary): ?string => $summary->bestPace('5min'))
        ->sort()
        ->values()
        ->all();

    expect($paces)->toBe(['4:30', '4:40']);
});

it('skips runs without a stream summary', function (): void {
    $user = User::factory()->create();
    streamRun($user, '2026-05-31 06:00:00', null);

    expect((new ResolveRecentStreamSummariesAction())($user, Carbon::parse('2026-06-01'), 22))->toBe([]);
});

it('reads the widest window any consumer asks for up front', function (): void {
    $user = User::factory()->create();
    streamRun($user, '2026-05-31 06:00:00', ['best_5min_pace' => '4:30']);
    streamRun($user, '2026-05-01 06:00:00', ['best_5min_pace' => '4:40']);
    $resolve = new ResolveRecentStreamSummariesAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $narrow = $resolve($user, Carbon::parse('2026-06-01'), 10);
    $wide = $resolve($user, Carbon::parse('2026-06-01'), 60);

    expect($narrow)->toHaveCount(1)
        ->and($wide)->toHaveCount(2)
        ->and($queries)->toBe(1);
});

it('reads again when asked for a window beyond the one it holds', function (): void {
    $user = User::factory()->create();
    streamRun($user, '2026-05-31 06:00:00', ['best_5min_pace' => '4:30']);
    streamRun($user, '2026-02-15 06:00:00', ['best_5min_pace' => '4:40']);
    $resolve = new ResolveRecentStreamSummariesAction();

    $held = $resolve($user, Carbon::parse('2026-06-01'), 10);
    $beyond = $resolve($user, Carbon::parse('2026-06-01'), 120);

    expect($held)->toHaveCount(1)
        ->and($beyond)->toHaveCount(2);
});

it('is one shared instance per request', function (): void {
    expect(app(ResolveRecentStreamSummariesAction::class))->toBe(app(ResolveRecentStreamSummariesAction::class));
});
