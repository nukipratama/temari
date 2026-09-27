<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Run\Story\PastYouDuelBuilder;

/**
 * @param  list<array{split: int, distance: float, elapsed_time: float}>  $splits
 */
function duelDetail(array $splits, float $distanceM): ActivityDetail
{
    return new ActivityDetail([
        'splits_metric' => $splits,
        'distance' => $distanceM,
    ]);
}

function kmSplit(int $km, float $elapsedSec, float $distanceM = 1000.0): array
{
    return ['split' => $km, 'distance' => $distanceM, 'elapsed_time' => $elapsedSec];
}

it('builds a signed gap per compared km, ahead as negative and behind as positive', function (): void {
    $current = duelDetail([kmSplit(1, 240), kmSplit(2, 250)], 2000.0);
    $past = duelDetail([kmSplit(1, 246), kmSplit(2, 241)], 2000.0);

    $duel = app(PastYouDuelBuilder::class)->build($current, $past);

    expect($duel['gaps'])->toBe([
        ['km' => 1, 'gap_sec' => -6, 'label' => '−0:06'],
        ['km' => 2, 'gap_sec' => 9, 'label' => '+0:09'],
    ])
        ->and($duel['net_gap_sec'])->toBe(3)
        ->and($duel['net_label'])->toBe('+0:03')
        ->and($duel['compared_km'])->toBe(2);
});

it('labels a dead-even km as "even"', function (): void {
    $current = duelDetail([kmSplit(1, 240)], 1000.0);
    $past = duelDetail([kmSplit(1, 240)], 1000.0);

    $duel = app(PastYouDuelBuilder::class)->build($current, $past);

    expect($duel['gaps'][0]['label'])->toBe('even')
        ->and($duel['footnote'])->toBeNull();
});

it('compares only the whole km both runs covered and footnotes the remainder', function (): void {
    $current = duelDetail([kmSplit(1, 240), kmSplit(2, 245), kmSplit(3, 250)], 3400.0);
    $past = duelDetail([kmSplit(1, 246), kmSplit(2, 241)], 2000.0);

    $duel = app(PastYouDuelBuilder::class)->build($current, $past);

    expect($duel['compared_km'])->toBe(2)
        ->and($duel['footnote'])->toBe('+1.4 km past you didn\'t run');
});

it('names the viewer as the one who didn\'t run when the past run went further', function (): void {
    $current = duelDetail([kmSplit(1, 240)], 1000.0);
    $past = duelDetail([kmSplit(1, 246), kmSplit(2, 241)], 2400.0);

    $duel = app(PastYouDuelBuilder::class)->build($current, $past);

    expect($duel['footnote'])->toBe('+1.4 km you didn\'t run');
});

it('ignores a partial split short of a full km', function (): void {
    $current = duelDetail([kmSplit(1, 240), kmSplit(2, 100, 400.0)], 1400.0);
    $past = duelDetail([kmSplit(1, 246), kmSplit(2, 241)], 2000.0);

    $duel = app(PastYouDuelBuilder::class)->build($current, $past);

    expect($duel['compared_km'])->toBe(1)
        ->and($duel['gaps'])->toHaveCount(1);
});

it('returns null when either run has no splits, so the card renders as it does today', function (): void {
    $current = duelDetail([kmSplit(1, 240)], 1000.0);
    $notHydrated = new ActivityDetail(['splits_metric' => null, 'distance' => 1000.0]);

    expect(app(PastYouDuelBuilder::class)->build($current, $notHydrated))->toBeNull()
        ->and(app(PastYouDuelBuilder::class)->build($notHydrated, $current))->toBeNull();
});
