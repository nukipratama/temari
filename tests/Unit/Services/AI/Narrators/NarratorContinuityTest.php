<?php

declare(strict_types=1);

use App\Services\AI\Narrators\NarratorContinuity;

it('states the continuity rule in terms of avoiding repetition, not forcing a callback', function (): void {
    expect(NarratorContinuity::RULE)
        ->toBeString()
        ->toContain('prev_opener')
        ->toContain('MENGHINDARI pengulangan')
        // The old crutch phrasing must not creep back in.
        ->not->toContain('lanjutkan benang');
});

it('derives an opener from the first few words of a previous narrative', function (): void {
    expect(NarratorContinuity::opener('Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup dan rapi.'))
        ->toBe('Masih nyambung dari sesi kemarin, kali ini penutupmu lebih hidup');
});

it('returns null when there is no previous narrative', function (): void {
    expect(NarratorContinuity::opener(null))->toBeNull();
});

it('does not pad a short narrative', function (): void {
    expect(NarratorContinuity::opener('Lari kemarin enteng banget.'))
        ->toBe('Lari kemarin enteng banget.');
});

it('bundles prev_narrative and prev_opener into one context slice', function (): void {
    expect(NarratorContinuity::fields('Lari kemarin enteng banget.'))
        ->toBe([
            'prev_narrative' => 'Lari kemarin enteng banget.',
            'prev_opener' => 'Lari kemarin enteng banget.',
        ]);
});

it('keys fields() off the shared CONTEXT_KEYS constant', function (): void {
    expect(NarratorContinuity::CONTEXT_KEYS)->toBe(['prev_narrative', 'prev_opener'])
        ->and(array_keys(NarratorContinuity::fields('Lari kemarin enteng banget.')))
        ->toBe(NarratorContinuity::CONTEXT_KEYS);
});

it('bundles null fields when there is no previous narrative', function (): void {
    expect(NarratorContinuity::fields(null))
        ->toBe([
            'prev_narrative' => null,
            'prev_opener' => null,
        ]);
});
