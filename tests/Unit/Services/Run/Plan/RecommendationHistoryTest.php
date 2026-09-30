<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('retains immutable advice across daily row deletion and regeneration', function (): void {
    $session = PlannedSession::factory()->create(['date' => '2026-10-01']);
    $history = app(RecommendationHistory::class);
    $revision = $history->record($session->user_id, $session->date->toDateString(), ['session_type' => 'tempo'], ['session_type' => 'easy', 'distance_km' => 6.8]);
    $session->delete();
    $replacement = PlannedSession::factory()->create(['user_id' => $revision->user_id, 'date' => '2026-10-01']);

    expect($history->record($replacement->user_id, $replacement->date->toDateString(), ['session_type' => 'tempo'], ['session_type' => 'easy', 'distance_km' => 6.8])->id)->toBe($revision->id)
        ->and($revision->refresh()->original)->toBe(['session_type' => 'tempo']);
});

it('uses the last actually shown revision before UTC activity start and ignores unseen updates', function (): void {
    $session = PlannedSession::factory()->create(['date' => '2026-10-01']);
    $history = app(RecommendationHistory::class);
    $first = $history->record($session->user_id, $session->date->toDateString(), ['session_type' => 'tempo'], ['session_type' => 'easy']);
    $second = $history->record($session->user_id, $session->date->toDateString(), ['session_type' => 'tempo'], ['session_type' => 'tempo']);
    Carbon::setTestNow('2026-10-01 10:00:00 UTC');
    $history->shown($first, (string) Str::uuid());
    $run = ActivityDetail::factory()->for(Activity::factory()->for($session->user))->create([
        'start_date_local' => '2026-10-01 18:00:00', 'start_date_utc' => '2026-10-01 11:00:00',
    ]);
    expect($history->beforeRun($session->user_id, $run)?->id)->toBe($first->id);
    Carbon::setTestNow('2026-10-01 12:00:00 UTC');
    $history->shown($second, (string) Str::uuid());
    expect($history->beforeRun($session->user_id, $run)?->id)->toBe($first->id);
    Carbon::setTestNow();
});

it('keeps repeated receipt requests idempotent without advancing their observation time', function (): void {
    $session = PlannedSession::factory()->create();
    $history = app(RecommendationHistory::class);
    $revision = $history->record($session->user_id, $session->date->toDateString(), [], ['session_type' => 'easy']);
    $receipt = (string) Str::uuid();
    Carbon::setTestNow('2026-10-01 10:00:00 UTC');
    $first = $history->shown($revision, $receipt);
    Carbon::setTestNow('2026-10-01 12:00:00 UTC');
    expect($history->shown($revision, $receipt)->shown_at->equalTo($first->shown_at))->toBeTrue();
    Carbon::setTestNow();
});

it('does not invent shown advice or order an activity with an unknown UTC start', function (): void {
    $session = PlannedSession::factory()->create();
    $history = app(RecommendationHistory::class);
    $revision = $history->record($session->user_id, $session->date->toDateString(), [], ['session_type' => 'easy']);
    $run = ActivityDetail::factory()->for(Activity::factory()->for($session->user))->create(['start_date_utc' => null]);
    $history->shown($revision, (string) Str::uuid());
    expect($history->beforeRun($session->user_id, $run))->toBeNull()
        ->and($history->beforeRun(User::factory()->create()->id, $run))->toBeNull();
    $run->update(['start_date_utc' => '2026-10-01 00:00:00']);
    expect($history->beforeRun(User::factory()->create()->id, $run))->toBeNull();
});

it('retains policy identity and rejects reusing a receipt for different advice', function (): void {
    $session = PlannedSession::factory()->create();
    $history = app(RecommendationHistory::class);
    $first = $history->record($session->user_id, $session->date->toDateString(), [], [], 1);
    $second = $history->record($session->user_id, $session->date->toDateString(), [], [], 2);
    expect($second->id)->not->toBe($first->id)->and($second->policy_version)->toBe(2);
    $receipt = (string) Str::uuid();
    $history->shown($first, $receipt);
    expect(fn () => $history->shown($second, $receipt))->toThrow(HttpException::class);
});
