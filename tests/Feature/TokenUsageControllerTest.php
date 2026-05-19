<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function seedUsage(string $kind, int $prompt, int $completion, Carbon $when): void
{
    TokenUsage::query()->create([
        'kind' => $kind,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'model' => 'gpt-test',
        'created_at' => $when,
    ]);
}

it('requires authentication', function (): void {
    $this->get('/ai-usage')->assertRedirect('/login');
});

it('renders the AiUsage page with totals + per-kind breakdown filtered by date', function (): void {
    $user = User::factory()->create();
    seedUsage('briefing', 100, 50, Carbon::parse('2026-05-10 09:00:00'));
    seedUsage('briefing', 200, 80, Carbon::parse('2026-05-15 11:00:00'));
    seedUsage('run-insight', 300, 150, Carbon::parse('2026-05-12 13:00:00'));
    seedUsage('briefing', 999, 999, Carbon::parse('2026-04-30 23:00:00')); // outside range

    $this->actingAs($user)
        ->get('/ai-usage?from=2026-05-01&to=2026-05-19')
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
            ])
            ->has('byKind', 2)
            ->where('byKind.0', [
                'kind' => 'run-insight',
                'prompt' => 300,
                'completion' => 150,
                'total' => 450,
                'calls' => 1,
            ])
            ->where('byKind.1', [
                'kind' => 'briefing',
                'prompt' => 300,
                'completion' => 130,
                'total' => 430,
                'calls' => 2,
            ]),
        );
});

it('defaults to start of current month when from is omitted', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $user = User::factory()->create();
    seedUsage('inside', 50, 50, Carbon::parse('2026-05-03'));
    seedUsage('outside', 50, 50, Carbon::parse('2026-04-25'));

    $this->actingAs($user)
        ->get('/ai-usage')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
            ->where('from', '2026-05-01')
            ->where('totals.calls', 1),
        );

    Carbon::setTestNow();
});

it('rejects malformed date inputs', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/ai-usage?from=yesterday')
        ->assertStatus(422);
});

it('returns zeroed totals and empty breakdown when no rows fall within range', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/ai-usage?from=2026-05-01&to=2026-05-19')
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
            ->where('totals', [
                'prompt' => 0,
                'completion' => 0,
                'total' => 0,
                'calls' => 0,
            ])
            ->has('byKind', 0),
        );
});
