<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('casts its reference and refuses to advance a persisted view timestamp', function (): void {
    $session = PlannedSession::factory()->create();
    $history = app(RecommendationHistory::class);
    $revision = $history->record($session->user_id, $session->date->toDateString(), [], []);
    $view = $history->shown($revision, (string) Str::uuid());
    expect($view->recommendation_revision_id)->toBeInt();
    expect(fn () => $view->update(['shown_at' => now()->addHour()]))->toThrow(LogicException::class);
});
