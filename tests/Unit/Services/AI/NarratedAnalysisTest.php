<?php

declare(strict_types=1);

use App\Services\AI\NarratedAnalysis;

it('reads null until a narration declares itself', function (): void {
    expect(new NarratedAnalysis()->current())->toBeNull();
});

it('holds the row id for the length of the narration and restores what it found', function (): void {
    $narrated = new NarratedAnalysis();

    $inner = $narrated->during(7, fn (): ?int => $narrated->during(9, fn (): ?int => $narrated->current()));

    expect($inner)->toBe(9)
        ->and($narrated->current())->toBeNull();
});

it('restores the previous id when the narration throws, so a failed run cannot leak attribution', function (): void {
    $narrated = new NarratedAnalysis();

    expect(fn () => $narrated->during(7, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect($narrated->current())->toBeNull();
});
