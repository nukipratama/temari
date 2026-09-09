<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Models\Analytics\StravaSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('deletes ai_token_usages and strava_sync_logs rows older than 90 days', function (): void {
    $old = TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1, 'completion_tokens' => 1,
        'total_tokens' => 2, 'model' => 'gpt-4o', 'created_at' => Carbon::now()->subDays(91),
    ]);
    $recent = TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1, 'completion_tokens' => 1,
        'total_tokens' => 2, 'model' => 'gpt-4o', 'created_at' => Carbon::now()->subDays(89),
    ]);

    $oldLog = StravaSyncLog::log(userId: 1, status: 'success');
    $oldLog->update(['synced_at' => Carbon::now()->subDays(91)]);
    $recentLog = StravaSyncLog::log(userId: 1, status: 'success');
    $recentLog->update(['synced_at' => Carbon::now()->subDays(89)]);

    $this->artisan('analytics:prune')->assertSuccessful();

    expect(TokenUsage::query()->find($old->id))->toBeNull()
        ->and(TokenUsage::query()->find($recent->id))->not->toBeNull()
        ->and(StravaSyncLog::query()->find($oldLog->id))->toBeNull()
        ->and(StravaSyncLog::query()->find($recentLog->id))->not->toBeNull();
});
