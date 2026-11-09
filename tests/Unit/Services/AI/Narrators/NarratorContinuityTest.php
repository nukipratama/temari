<?php

declare(strict_types=1);

use App\Services\AI\Narrators\NarratorContinuity;

it('states the continuity rule in terms of avoiding repetition, not forcing a callback', function (): void {
    expect(NarratorContinuity::RULE)
        ->toBeString()
        ->toContain('prev_opener')
        ->toContain('AVOID repetition')
        // The old crutch phrasing must not creep back in.
        ->and(NarratorContinuity::RULE)->not->toContain('lanjutkan benang');
});

it('tells the model to open differently from all of recent_openers', function (): void {
    expect(NarratorContinuity::RULE)
        ->toContain('recent_openers')
        ->toContain('open differently from all of them');
});

it('bundles prev_narrative, prev_opener and recent_openers into one context slice', function (): void {
    expect(NarratorContinuity::fields(['The run yesterday was very easy.']))
        ->toBe([
            'prev_narrative' => 'The run yesterday was very easy.',
            'prev_opener' => 'The run yesterday was very easy.',
            'recent_openers' => ['The run yesterday was very easy.'],
        ]);
});

it('keys fields() off the shared CONTEXT_KEYS constant', function (): void {
    expect(NarratorContinuity::CONTEXT_KEYS)->toBe(['prev_narrative', 'prev_opener', 'recent_openers'])
        ->and(array_keys(NarratorContinuity::fields(['The run yesterday was very easy.'])))
        ->toBe(NarratorContinuity::CONTEXT_KEYS)
        ->and(array_keys(NarratorContinuity::fields([])))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('bundles empty fields when there is no previous narrative', function (): void {
    expect(NarratorContinuity::fields([]))
        ->toBe([
            'prev_narrative' => null,
            'prev_opener' => null,
            'recent_openers' => [],
        ]);
});

it('truncates a long plain narration to its first ten words for the opener', function (): void {
    expect(NarratorContinuity::fields(['Still carrying yesterday, and this time your close was livelier and tidier.']))
        ->toMatchArray([
            'prev_opener' => 'Still carrying yesterday, and this time your close was livelier',
        ]);
});

it('lists the opener of every previous narration newest first, mixing JSON and plain rows', function (): void {
    $fields = NarratorContinuity::fields([
        json_encode(['title' => 'a steady week', 'body' => 'Four easy runs held the line.'], JSON_THROW_ON_ERROR),
        'Plain narration that opens with a lot of words in this old row, then goes on.',
        json_encode(['title' => null, 'body' => 'Bodies without a headline open with their first words in this one.'], JSON_THROW_ON_ERROR),
    ]);

    expect($fields['prev_narrative'])->toBe("a steady week\n\nFour easy runs held the line.")
        ->and($fields['prev_opener'])->toBe('a steady week')
        ->and($fields['recent_openers'])->toBe([
            'a steady week',
            'Plain narration that opens with a lot of words in',
            'Bodies without a headline open with their first words in',
        ]);
});

it('passes at most five openers', function (): void {
    $contents = array_map(fn (int $i): string => "Narration number {$i}", range(1, 7));

    expect(NarratorContinuity::fields($contents)['recent_openers'])
        ->toBe(array_slice($contents, 0, 5));
});
