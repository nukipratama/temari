<?php

declare(strict_types=1);

use App\Services\AI\Anchor\CitationValidator;
use Illuminate\Support\Facades\Log;

$always = fn (string $anchor): bool => true;
$never = fn (string $anchor): bool => false;

it('keeps a citation whose anchor resolves', function () use ($always): void {
    $text = 'keeping this to [an easy run](session:today) today';

    expect(new CitationValidator()->keepResolving($text, $always, 'briefing_mascot_voice'))
        ->toBe($text);
});

it('unwraps a citation that does not resolve, leaving the words', function () use ($never): void {
    Log::spy();

    $out = new CitationValidator()->keepResolving(
        'keeping this to [an easy run](session:today) today',
        $never,
        'briefing_mascot_voice',
    );

    expect($out)->toBe('keeping this to an easy run today');
    Log::shouldHaveReceived('info')->once();
});

/** The voice allows one marked span per block; the model may still mark two. */
it('keeps only the first resolving citation', function () use ($always): void {
    $out = new CitationValidator()->keepResolving(
        '[first](session:today) then [second](session:today)',
        $always,
        'briefing_mascot_voice',
    );

    expect($out)->toBe('[first](session:today) then second');
});

it('leaves narration carrying no citation untouched', function () use ($always): void {
    $text = "Easy run, 25-30 minutes.\n\nslow, effort by feel.";

    expect(new CitationValidator()->keepResolving($text, $always, 'briefing_mascot_voice'))
        ->toBe($text);
});

/** A URL is not an anchor, and the resolver is what says so. */
it('unwraps a link the model invented', function (): void {
    Log::spy();

    $out = new CitationValidator()->keepResolving(
        'see [the plan](https://example.com/plan) for more',
        fn (string $anchor): bool => $anchor === 'session:today',
        'briefing_mascot_voice',
    );

    expect($out)->toBe('see the plan for more');
});
