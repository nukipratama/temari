<?php

declare(strict_types=1);

use App\Services\AI\Anchor\RunAnchorResolver;
use App\Services\Run\Metrics\StreamSummary;

function runAnchorSummary(array $overrides = []): StreamSummary
{
    return StreamSummary::fromArray(array_merge([
        'per_km' => [['km' => 1], ['km' => 2], ['km' => 3]],
        'time_in_zone_pct' => ['Z2' => 100],
        'decoupling_pct' => 3.1,
    ], $overrides));
}

it('resolves a split the run actually ran', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('split:3', runAnchorSummary()))->toBeTrue();
});

/** A model told "cite a split" will invent one past the end of a short run. */
it('refuses a split past the end of the run', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('split:4', runAnchorSummary()))->toBeFalse();
});

it('refuses every split when the run has no per-km data at all', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('split:1', runAnchorSummary(['per_km' => null])))->toBeFalse();
});

it('resolves a zone whenever the run measured any zone distribution', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('zone:z4', runAnchorSummary()))->toBeTrue();
});

it('refuses a zone on a run with no heart-rate data', function (): void {
    $resolver = new RunAnchorResolver();
    $summary = runAnchorSummary(['time_in_zone_pct' => [], 'time_in_zone_min' => null]);

    expect($resolver->resolves('zone:z2', $summary))->toBeFalse();
});

it('resolves a metric this run measured and refuses one it did not', function (): void {
    $resolver = new RunAnchorResolver();
    $summary = runAnchorSummary();

    expect($resolver->resolves('metric:decoupling', $summary))->toBeTrue()
        ->and($resolver->resolves('metric:gap_pace', $summary))->toBeFalse();
});

it('resolves every metric the run measured', function (string $metric, array $overrides): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves("metric:{$metric}", runAnchorSummary($overrides)))->toBeTrue();
})->with([
    ['hr_drift', ['hr_drift_bpm' => 4.0]],
    ['cadence_drop', ['cadence_drop_spm' => 2.0]],
    ['pace_variability', ['pace_variability_sec' => 11.0]],
    ['grade', ['max_grade_pct' => 6.0]],
    ['gap_pace', ['gap_pace' => '5:30']],
]);

/**
 * A computed false is a real reading — the run was measured and did not
 * negative-split. Only the key's absence means it was never measured.
 */
it('reads a negative_split of false as measured, not as missing', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('metric:negative_split', runAnchorSummary(['negative_split' => false])))->toBeTrue()
        ->and($resolver->resolves('metric:negative_split', runAnchorSummary()))->toBeFalse();
});

/** The metric set is exhaustive; an invented name is not a near-miss to repair. */
it('refuses a metric name outside the known set', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('metric:vo_two_max', runAnchorSummary()))->toBeFalse();
});

it('refuses an anchor outside the grammar entirely', function (): void {
    $resolver = new RunAnchorResolver();

    expect($resolver->resolves('lap:2', runAnchorSummary()))->toBeFalse();
});
