<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityStream;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('round-trips the streams array via the cast', function (): void {
    $payload = [
        'time' => ['data' => [0, 1, 2]],
        'heartrate' => ['data' => [120, 125, 130]],
    ];
    $stream = ActivityStream::factory()->create(['data' => $payload]);

    expect($stream->fresh()->data)->toBe($payload);
});

it('belongs to one activity and enforces uniqueness', function (): void {
    $activity = Activity::factory()->create();
    ActivityStream::factory()->for($activity)->create();

    expect(fn () => ActivityStream::factory()->for($activity)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});
