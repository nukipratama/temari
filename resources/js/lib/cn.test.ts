import { describe, expect, it } from 'vitest';
import { cn } from './cn';

describe('cn', () => {
    it('joins truthy class names with spaces', () => {
        expect(cn('a', 'b', 'c')).toBe('a b c');
    });

    it('filters out falsy values', () => {
        expect(cn('a', false, 'b', null, undefined, 'c')).toBe('a b c');
    });

    it('returns empty string when all inputs are falsy', () => {
        expect(cn(false, null, undefined)).toBe('');
    });

    it('handles a single class', () => {
        expect(cn('only')).toBe('only');
    });

    it('merges conflicting tailwind utilities so the last one wins', () => {
        expect(cn('px-2', 'px-4')).toBe('px-4');
        expect(cn('text-sm', false, 'text-lg')).toBe('text-lg');
    });

    it('keeps a label-tier utility alongside a text color', () => {
        // text-label-* bundle no color, so they must coexist with text-ink-*.
        expect(cn('text-label-small', 'text-ink-2')).toBe('text-label-small text-ink-2');
        expect(cn('text-label-micro', 'text-ink-on-sky')).toBe('text-label-micro text-ink-on-sky');
    });

    it('treats the label tiers as font sizes that override each other', () => {
        expect(cn('text-label-small', 'text-label-micro')).toBe('text-label-micro');
        expect(cn('text-label-small', 'text-lg')).toBe('text-lg');
    });
});
