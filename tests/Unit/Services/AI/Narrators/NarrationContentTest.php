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
