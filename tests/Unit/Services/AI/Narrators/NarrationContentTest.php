<?php

declare(strict_types=1);

use App\Services\AI\Narrators\NarrationContent;

it('reads null as no narration', function (): void {
    expect(NarrationContent::fromStored(null))->toBeNull();
});

it('treats plain text as a body with no title', function (): void {
    $content = nonNull(NarrationContent::fromStored('Cadence was 168 yesterday.'));

    expect($content->title)->toBeNull()
        ->and($content->body)->toBe('Cadence was 168 yesterday.');
});

it('reads a JSON object with a title and a body', function (): void {
    $content = nonNull(NarrationContent::fromStored('{"title":"a steady week","body":"Four easy runs.","claims":[{"x":1}]}'));

    expect($content->title)->toBe('a steady week')
        ->and($content->body)->toBe('Four easy runs.');
});

it('reads a JSON object with a body and no title or a non-string title', function (string $json): void {
    $content = nonNull(NarrationContent::fromStored($json));

    expect($content->title)->toBeNull()
        ->and($content->body)->toBe('Four easy runs.');
})->with([
    'no title' => ['{"body":"Four easy runs."}'],
    'null title' => ['{"title":null,"body":"Four easy runs."}'],
    'numeric title' => ['{"title":3,"body":"Four easy runs."}'],
    'empty title' => ['{"title":"","body":"Four easy runs."}'],
    'blank title' => ['{"title":"  ","body":"Four easy runs."}'],
]);

it('treats malformed JSON as a raw body', function (): void {
    $content = nonNull(NarrationContent::fromStored('{"title":"cut off","body":'));

    expect($content->title)->toBeNull()
        ->and($content->body)->toBe('{"title":"cut off","body":');
});

it('treats JSON without a string body as a raw body', function (string $raw): void {
    $content = nonNull(NarrationContent::fromStored($raw));

    expect($content->title)->toBeNull()
        ->and($content->body)->toBe($raw);
})->with([
    'array' => ['["a","b"]'],
    'object without body' => ['{"title":"only a title"}'],
    'numeric body' => ['{"body":4}'],
    'scalar' => ['42'],
]);

it('stores the title and body as JSON with unicode left readable', function (): void {
    $stored = new NarrationContent('minggu yang tenang', 'Empat lari santai, 5 km.')->toStored();

    expect($stored)->toBe('{"title":"minggu yang tenang","body":"Empat lari santai, 5 km."}')
        ->and(NarrationContent::fromStored($stored)?->title)->toBe('minggu yang tenang');
});

it('lowercases an ordinary-word title on write and leaves the body and other titles alone', function (string $title, string $expected): void {
    $stored = new NarrationContent($title, 'Four easy runs.')->toStored();

    expect(NarrationContent::fromStored($stored))->toEqual(new NarrationContent($expected, 'Four easy runs.'));
})->with([
    'an ordinary word' => ['A steady week', 'a steady week'],
    'an acronym' => ['HR held all week', 'HR held all week'],
    'a digit' => ['28.4 km, up from 19', '28.4 km, up from 19'],
    'the pronoun I' => ['I called it early', 'i called it early'],
]);

it('keeps the casing of a title already read from storage', function (): void {
    $content = nonNull(NarrationContent::fromStored('{"title":"A Steady Week","body":"Four easy runs."}'));

    expect($content->title)->toBe('A Steady Week')
        ->and($content->plainText())->toBe("A Steady Week\n\nFour easy runs.");
});

it('stores a missing title as null', function (): void {
    expect(new NarrationContent(null, 'Body only.')->toStored())->toBe('{"title":null,"body":"Body only."}');
});

it('joins title and body into plain text, or gives the body alone', function (): void {
    expect(new NarrationContent('a steady week', 'Four easy runs.')->plainText())->toBe("a steady week\n\nFour easy runs.")
        ->and(new NarrationContent(null, 'Four easy runs.')->plainText())->toBe('Four easy runs.');
});

it('opens with the title when present, else the first words of the body', function (): void {
    expect(new NarrationContent('a steady week', 'Four easy runs held the line all week long.')->opener())->toBe('a steady week')
        ->and(new NarrationContent(null, 'Still carrying yesterday, and this time your close was livelier and tidier.')->opener())
        ->toBe('Still carrying yesterday, and this time your close was livelier')
        ->and(new NarrationContent(null, 'The run yesterday was very easy.')->opener())->toBe('The run yesterday was very easy.')
        ->and(new NarrationContent(null, 'one two three four five')->opener(3))->toBe('one two three');
});

it('round-trips anchored claims next to the title and body', function (): void {
    $claims = [
        ['anchor' => 'split:4', 'text' => 'Km 4 was the quickest.', 'value' => '5:18/km', 'delta' => '-0:14 vs km 3'],
        ['anchor' => 'metric:decoupling', 'text' => 'Heart rate held against pace.', 'value' => null, 'delta' => null],
    ];

    $stored = new NarrationContent('a quiet tempo that held', 'Body.', $claims)->toStored();

    expect($stored)->toBe(json_encode(['title' => 'a quiet tempo that held', 'body' => 'Body.', 'claims' => $claims], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->and(NarrationContent::fromStored($stored)?->claims)->toBe($claims);
});

it('stores an empty claims list, and leaves claims out when there are none to carry', function (): void {
    expect(new NarrationContent('t', 'b', [])->toStored())->toBe('{"title":"t","body":"b","claims":[]}')
        ->and(NarrationContent::fromStored('{"title":"t","body":"b","claims":[]}')?->claims)->toBe([])
        ->and(new NarrationContent('t', 'b')->toStored())->toBe('{"title":"t","body":"b"}')
        ->and(NarrationContent::fromStored('{"title":"t","body":"b"}')?->claims)->toBeNull()
        ->and(NarrationContent::fromStored('Plain speech.')?->claims)->toBeNull();
});

it('keeps only claims with a string anchor and text, reading a non-string value or delta as null', function (): void {
    $content = nonNull(NarrationContent::fromStored(json_encode(['body' => 'b', 'claims' => [
        ['anchor' => 'zone:z2', 'text' => 'Mostly Z2.', 'value' => 70, 'delta' => null],
        ['anchor' => 'split:1'],
        'not a claim',
        ['anchor' => 3, 'text' => 'numeric anchor'],
    ]], JSON_THROW_ON_ERROR)));

    expect($content->claims)->toBe([['anchor' => 'zone:z2', 'text' => 'Mostly Z2.', 'value' => null, 'delta' => null]]);
});

it('reads a claims value that is not a list as no claims', function (): void {
    expect(NarrationContent::fromStored('{"body":"b","claims":"split:1"}')?->claims)->toBeNull();
});

it('leaves claims out of the plain text and the opener', function (): void {
    $content = new NarrationContent('a quiet tempo', 'The body.', [['anchor' => 'split:1', 'text' => 'Km 1 ran easy.', 'value' => null, 'delta' => null]]);

    expect($content->plainText())->toBe("a quiet tempo\n\nThe body.")
        ->and($content->opener())->toBe('a quiet tempo');
});
