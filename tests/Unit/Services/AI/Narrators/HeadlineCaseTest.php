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
