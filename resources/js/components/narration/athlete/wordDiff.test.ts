import { describe, expect, it } from 'vitest';

import { wordDiff } from './wordDiff';

describe('wordDiff', () => {
    it('marks unchanged prose as one same run', () => {
        expect(wordDiff('a steady five', 'a steady five')).toEqual([
            { id: 0, op: 'same', text: 'a steady five' },
        ]);
    });

    it('marks appended words as added', () => {
        const tokens = wordDiff('a steady five', 'a steady five kay');

        expect(tokens.map((t) => t.op)).toEqual(['same', 'added']);
        expect(tokens[1].text).toBe('kay');
    });

    it('marks dropped words as removed', () => {
        const tokens = wordDiff('a steady five kay', 'a steady five');

        expect(tokens[1]).toMatchObject({ op: 'removed', text: 'kay' });
    });

    it('keeps the common subsequence around a replacement', () => {
        const tokens = wordDiff('you ran slow today', 'you ran fast today');

        expect(tokens.map((t) => `${t.op}:${t.text}`)).toEqual([
            'same:you ran',
            'removed:slow',
            'added:fast',
            'same:today',
        ]);
    });

    it('handles an empty before as all added', () => {
        expect(wordDiff('', 'brand new')).toEqual([
            { id: 0, op: 'added', text: 'brand new' },
        ]);
    });

    it('collapses whitespace runs rather than emitting empty tokens', () => {
        expect(wordDiff('  ', '')).toEqual([]);
    });
});
