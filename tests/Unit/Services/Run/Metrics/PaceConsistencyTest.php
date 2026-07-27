<?php

declare(strict_types=1);

use App\Services\Run\Metrics\PaceConsistency;

it('returns null when there is no variability figure', function (): void {
    expect(PaceConsistency::label(null))->toBeNull()
        ->and(PaceConsistency::isPraiseworthy(null))->toBeFalse()
        ->and(PaceConsistency::isNotablyUneven(null))->toBeFalse();
});

it('labels each band', function (float $sec, string $expected): void {
    expect(PaceConsistency::label($sec))->toBe($expected);
})->with([
    'metronomic' => [3.0, 'sangat rata'],
    'on the very-even edge' => [8.0, 'sangat rata'],
    'controlled' => [12.0, 'cukup rata'],
    'on the even edge' => [15.0, 'cukup rata'],
    'swinging' => [18.0, 'agak naik-turun'],
    'on the uneven edge' => [20.0, 'agak naik-turun'],
    'ragged' => [45.0, 'naik-turun'],
]);

it('treats even pacing as worth complimenting', function (): void {
    expect(PaceConsistency::isPraiseworthy(8.0))->toBeTrue()
        ->and(PaceConsistency::isPraiseworthy(15.0))->toBeTrue()
        ->and(PaceConsistency::isPraiseworthy(15.1))->toBeFalse();
});

it('only flags a swing wider than the uneven band', function (): void {
    expect(PaceConsistency::isNotablyUneven(20.0))->toBeFalse()
        ->and(PaceConsistency::isNotablyUneven(20.1))->toBeTrue();
});

// The bands describe per-km split spread. Under the old per-second definition a
// steady run measured ~95 sec, so every run in the corpus read as ragged and the
// two praise bands were unreachable. Splits 26 seconds apart must be praised.
it('praises a run whose kilometre splits sit within half a minute', function (): void {
    $splitPaces = [473.0, 463.0, 484.0, 477.0, 458.0];
    $mean = array_sum($splitPaces) / count($splitPaces);
    $variance = array_sum(array_map(fn (float $p): float => ($p - $mean) ** 2, $splitPaces)) / count($splitPaces);

    expect(PaceConsistency::isPraiseworthy(round(sqrt($variance), 1)))->toBeTrue();
});
