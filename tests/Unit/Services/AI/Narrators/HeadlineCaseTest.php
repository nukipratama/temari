<?php

declare(strict_types=1);

use App\Services\AI\Narrators\HeadlineCase;

it('lowercases the first letter of an ordinary first word', function (string $text, string $expected): void {
    expect(HeadlineCase::lower($text))->toBe($expected);
})->with([
    'a plain word' => ['Four easy runs this week', 'four easy runs this week'],
    'an article' => ['A quiet month', 'a quiet month'],
    'the first sentence of several' => ["The long run carried it. Nothing else did.", "the long run carried it. Nothing else did."],
    'a contraction' => ["You're quicker than May", "you're quicker than May"],
    'leading whitespace' => ["  Steady week", "  steady week"],
    'a hyphenated word' => ['Half-marathon fitness', 'half-marathon fitness'],
    'an already lowercase headline' => ['four easy runs', 'four easy runs'],
]);

it('lowercases the pronoun I, alone or in a contraction', function (string $text, string $expected): void {
    expect(HeadlineCase::lower($text))->toBe($expected);
})->with([
    'I and a space' => ['I ran it twice', 'i ran it twice'],
    'I am' => ["I'm calling it a quiet week", "i'm calling it a quiet week"],
    'I would, typographic apostrophe' => ['I’d call that steady', 'i’d call that steady'],
    'I and a comma' => ['I, for one, noticed', 'i, for one, noticed'],
]);

it('leaves an acronym or a word with a capital inside alone', function (string $text): void {
    expect(HeadlineCase::lower($text))->toBe($text);
})->with([
    'HR' => ['HR stayed flat all week'],
    'PR' => ['PR at 10 km, by 14 seconds'],
    'TRIMP' => ['TRIMP climbed for the third week'],
    'an acronym with punctuation' => ['HR, for once, held'],
    'an internal capital' => ['McKinley Hill again, twice'],
    'an all-caps word' => ['EASY week'],
]);

it('leaves a first word that carries a digit alone', function (string $text): void {
    expect(HeadlineCase::lower($text))->toBe($text);
})->with([
    'a leading digit' => ['28.4 km, up from 19'],
    'a digit-led unit' => ['5K on a tired week'],
    'a zone' => ['Z2 held for most of it'],
    'a letter then a digit' => ['Km5 was the quickest'],
]);

it('leaves Strava alone', function (): void {
    expect(HeadlineCase::lower('Strava has the same run twice'))->toBe('Strava has the same run twice')
        ->and(HeadlineCase::lower("Strava's count says four"))->toBe("Strava's count says four");
});

it('leaves a headline that does not open on a capital letter alone', function (string $text): void {
    expect(HeadlineCase::lower($text))->toBe($text);
})->with([
    'a quoted name' => ['"Silent Steps" went Rare in the rain'],
    'a symbol' => ['~5 km easy'],
]);

it('returns an empty or blank string unchanged', function (string $text): void {
    expect(HeadlineCase::lower($text))->toBe($text);
})->with(['empty' => [''], 'blank' => ['   ']]);

it('leaves a rarity label alone', function (): void {
    expect(HeadlineCase::lower('Legendary card, ordinary morning'))->toBe('Legendary card, ordinary morning')
        ->and(HeadlineCase::lower('Rare one, this'))->toBe('Rare one, this')
        ->and(HeadlineCase::lower('Rarely this quiet'))->toBe('rarely this quiet');
});

it('leaves a weekday or month name alone', function (string $text): void {
    expect(HeadlineCase::lower($text))->toBe($text);
})->with([
    'a weekday' => ["Thursday's tempo got away"],
    'a month' => ['October came in at 112 km'],
]);

it('leaves a caller-supplied name alone, whole names only', function (): void {
    expect(HeadlineCase::lower('Steady Tempo held for 40 minutes', ['Steady Tempo']))->toBe('Steady Tempo held for 40 minutes')
        ->and(HeadlineCase::lower('Metronom, again', ['Metronom']))->toBe('Metronom, again')
        ->and(HeadlineCase::lower('Steady effort all the way', ['Steady Tempo']))->toBe('steady effort all the way')
        ->and(HeadlineCase::lower('Metronomic pacing', ['Metronom']))->toBe('metronomic pacing');
});

it('lowercases a one-liner and leaves a line of two or more sentences in sentence case', function (string $text, string $expected): void {
    expect(HeadlineCase::oneLiner($text))->toBe($expected);
})->with([
    'one sentence' => ['Your fastest 5k landed inside this one.', 'your fastest 5k landed inside this one.'],
    'a decimal' => ['Ran 28.4 km and it held.', 'ran 28.4 km and it held.'],
    'two sentences' => ['Your fastest 5k landed inside this one. The Legendary label is just the receipt.', 'Your fastest 5k landed inside this one. The Legendary label is just the receipt.'],
    'a question then a sentence' => ['Heavy legs? Today is easy.', 'Heavy legs? Today is easy.'],
]);
