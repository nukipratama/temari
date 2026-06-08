<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\RunCard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts badges to an array', function (): void {
    $card = RunCard::factory()->create([
        'badges' => ['hari_panas', 'negative_split'],
    ]);

    expect($card->fresh()->badges)->toBe(['hari_panas', 'negative_split']);
});

it('belongs to an activity and enforces one card per activity', function (): void {
    $activity = Activity::factory()->create();
    RunCard::factory()->for($activity)->create();

    expect(fn () => RunCard::factory()->for($activity)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('cascades deletion from activity', function (): void {
    $card = RunCard::factory()->create();
    $activityId = $card->activity_id;

    Activity::query()->whereKey($activityId)->delete();

    expect(RunCard::query()->find($card->id))->toBeNull();
});
