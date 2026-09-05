<?php

declare(strict_types=1);

use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

it('reads unattributed until an entry point declares itself', function (): void {
    expect(new NarrationOrigin()->current())->toBe(AnalysisOrigin::Unknown);
});

it('holds the origin the entry point set', function (): void {
    $holder = new NarrationOrigin();
    $holder->set(AnalysisOrigin::Ingest);

    expect($holder->current())->toBe(AnalysisOrigin::Ingest);
});

it('takes the latest declaration, so a nested dispatch attributes to its own entry point', function (): void {
    $holder = new NarrationOrigin();
    $holder->set(AnalysisOrigin::Scheduled);
    $holder->set(AnalysisOrigin::Recovery);

    expect($holder->current())->toBe(AnalysisOrigin::Recovery);
});

it('is scoped, so one queue job never inherits the previous job attribution', function (): void {
    app(NarrationOrigin::class)->set(AnalysisOrigin::User);
    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::User);

    app()->forgetScopedInstances();

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Unknown);
});
