<?php

declare(strict_types=1);

use App\Console\Commands\AI\NarrationEvalChecks;

function evalChecks(string $text, array $evidence = [], array $direction = []): array
{
    return NarrationEvalChecks::run($text, $evidence, $direction);
}

it('passes a clean answer on every check', function (): void {
    $evidence = ['get_day_plan' => ['distance_km' => 8.0, 'intent_detail' => 'averaged 7:30/km']];
    $direction = ['required' => ['easy'], 'forbidden' => ['harder than']];

    $results = evalChecks('8 km, easy the whole way at 7:30/km.', $evidence, $direction);

    expect(array_filter($results))->toBe([]);
});

it('fails the direction check on an inverted answer', function (): void {
    $direction = ['forbidden' => ['too hard|harder than|quicker than']];

    $results = evalChecks('ran harder than the easy day asked for.', [], $direction);

    expect($results['direction'])->toContain('harder than')
        ->and(array_filter($results, fn (?string $reason, string $check): bool => $check !== 'direction' && $reason !== null, ARRAY_FILTER_USE_BOTH))->toBe([]);
});

it('fails the direction check when no required wording appears', function (): void {
    $results = evalChecks('a day on the board.', [], ['required' => ['faster|quicker']]);

    expect($results['direction'])->toContain('none of');
});

it('fails the raw enum guard on a leaked enum value', function (): void {
    $results = evalChecks('the session came back too_hard today.');

    expect($results['raw_enum'])->toContain('too_hard');
});

it('allows bold but not backticks, since markdown is part of the voice', function (): void {
    expect(evalChecks('a **solid** day.')['markdown'])->toBeNull()
        ->and(evalChecks('a `solid` day.')['markdown'])->not->toBeNull();
});

it('fails the numbers check on a figure the evidence never held', function (): void {
    $evidence = ['run' => ['distance_km' => 8.0, 'pace_formatted' => '7:30']];

    expect(evalChecks('12.4 km at 7:30.', $evidence)['numbers'])->toContain('12.4')
        ->and(evalChecks('8 km at 7:30.', $evidence)['numbers'])->toBeNull();
});

it('accepts a rounded figure and a quoted string figure in the numbers check', function (): void {
    $evidence = ['run' => ['distance_km' => 7.9, 'intent_detail' => 'only 2 of 5 reps']];

    expect(evalChecks('8 km, only 2 of 5.', $evidence)['numbers'])->toBeNull();
});

it('fails the outcome label check on a verdict label', function (): void {
    expect(evalChecks('the pace sat easy, so it missed the hit mark.')['outcome_labels'])->not->toBeNull();
});

it('leaves markdown out of the outcome label check', function (): void {
    expect(evalChecks('a **solid** day.')['outcome_labels'])->toBeNull();
});
