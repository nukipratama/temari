<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanBriefingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->user = User::factory()->create();
    WeeklySnapshot::factory()->for($this->user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'runs' => 4,
        'distance_km' => 30.0,
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('builds the same briefing context once and hands it back for the same athlete and day', function (): void {
    $briefing = app(PlanBriefingContext::class);
    $first = $briefing->forUser($this->user, Carbon::today());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $briefing->forUser($this->user, Carbon::today());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second)->toBe($first)
        ->and($queries)->toBe([]);
});

it('builds a fresh briefing context for another day or another athlete', function (): void {
    $briefing = app(PlanBriefingContext::class);
    $today = $briefing->forUser($this->user, Carbon::today());

    expect($briefing->forUser($this->user, Carbon::today()->addDay()))->not->toBe($today)
        ->and($briefing->forUser(User::factory()->create(), Carbon::today()))->not->toBe($today);
});

it('is shared by every consumer within a request', function (): void {
    expect(app(PlanBriefingContext::class))->toBe(app(PlanBriefingContext::class));
});
