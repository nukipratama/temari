<?php

declare(strict_types=1);

use App\Models\FitnessAnchor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts the captured provisional fitness anchor and its PR provenance', function (): void {
    $user = User::factory()->create();
    $anchor = FitnessAnchor::query()->create([
        'user_id' => (string) $user->id,
        'vdot' => '28.5',
        'quality_vdot' => '33.6',
        'source_activity_id' => '123',
        'source_category' => 'half_marathon',
        'source_value_sec' => '8860.95',
        'set_at' => '2026-05-01',
        'quality_source_activity_id' => '456',
        'quality_source_category' => '5km',
        'quality_source_value_sec' => '1675',
        'quality_set_at' => '2026-06-01',
        'captured_at' => '2026-06-02 12:00:00',
    ]);

    expect($anchor->user_id)->toBeInt()
        ->and($anchor->vdot)->toBe(28.5)
        ->and($anchor->quality_vdot)->toBe(33.6)
        ->and($anchor->source_activity_id)->toBe(123)
        ->and($anchor->source_value_sec)->toBe(8860.95)
        ->and($anchor->quality_source_activity_id)->toBe(456)
        ->and($anchor->quality_source_value_sec)->toBe(1675.0)
        ->and($anchor->captured_at->toDateTimeString())->toBe('2026-06-02 12:00:00');
});
