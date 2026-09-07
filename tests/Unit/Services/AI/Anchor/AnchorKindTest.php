<?php

declare(strict_types=1);

use App\Services\AI\Anchor\AnchorKind;

it('parses each kind and hands back the value it names', function (string $anchor, AnchorKind $kind, string $value): void {
    expect(AnchorKind::parse($anchor))->toBe([$kind, $value]);
})->with([
    ['split:1', AnchorKind::Split, '1'],
    ['split:42', AnchorKind::Split, '42'],
    ['zone:z3', AnchorKind::Zone, 'z3'],
    ['metric:decoupling', AnchorKind::Metric, 'decoupling'],
    ['metric:gap_pace', AnchorKind::Metric, 'gap_pace'],
]);

/** Model output is untrusted, so a near-miss is a rejection rather than a repair. */
it('rejects anything outside the grammar', function (string $anchor): void {
    expect(AnchorKind::parse($anchor))->toBeNull();
})->with([
    'zero split' => ['split:0'],
    'leading zero' => ['split:01'],
    'negative split' => ['split:-1'],
    'zone out of range' => ['zone:z6'],
    'zone uppercase' => ['zone:Z3'],
    'metric uppercase' => ['metric:Decoupling'],
    'metric with a digit' => ['metric:vo2max'],
    'unknown kind' => ['lap:3'],
    'no value' => ['split:'],
    'trailing text' => ['split:1x'],
    'a url' => ['https://example.com'],
    'empty' => [''],
]);

it('reads a value only for its own kind', function (): void {
    expect(AnchorKind::Split->valueIn('split:4'))->toBe('4')
        ->and(AnchorKind::Zone->valueIn('split:4'))->toBeNull();
});
