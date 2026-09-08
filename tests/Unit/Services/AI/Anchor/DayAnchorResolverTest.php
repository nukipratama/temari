<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Services\AI\Anchor\DayAnchorResolver;

it('resolves the prescribed session when the plan covers the day', function (): void {
    $resolver = new DayAnchorResolver();

    expect($resolver->resolves('session:today', new PlannedSession()))->toBeTrue();
});

it('refuses the prescribed session on a day no plan covers', function (): void {
    $resolver = new DayAnchorResolver();

    expect($resolver->resolves('session:today', null))->toBeFalse();
});

/**
 * A model told to cite will reach for the run-page grammar it has seen
 * elsewhere. A day has no stream, so none of it resolves here.
 */
it('refuses every run-scoped anchor', function (string $anchor): void {
    $resolver = new DayAnchorResolver();

    expect($resolver->resolves($anchor, new PlannedSession()))->toBeFalse();
})->with(['split:1', 'zone:z3', 'metric:decoupling', 'metric:grade']);

it('refuses anything outside the grammar', function (string $anchor): void {
    $resolver = new DayAnchorResolver();

    expect($resolver->resolves($anchor, new PlannedSession()))->toBeFalse();
})->with(['session:tomorrow', 'session:2026-09-08', 'https://example.com', 'session', '']);
