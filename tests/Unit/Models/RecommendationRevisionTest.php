<?php

declare(strict_types=1);

use App\Models\RecommendationRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts its identity and refuses to mutate persisted advice', function (): void {
    $revision = RecommendationRevision::query()->create([
        'user_id' => (string) User::factory()->create()->id, 'date' => '2026-10-01',
        'policy_version' => '1', 'fingerprint' => str_repeat('a', 64), 'original' => [], 'effective' => [],
    ]);
    expect($revision->user_id)->toBeInt()->and($revision->policy_version)->toBe(1)
        ->and($revision->date->toDateString())->toBe('2026-10-01');
    expect(fn () => $revision->update(['effective' => ['session_type' => 'rest']]))->toThrow(LogicException::class);
});
