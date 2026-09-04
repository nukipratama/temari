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
        expect(cn('text-label-small', 'text-text-2')).toBe(
            'text-label-small text-text-2',
        );
        expect(cn('text-label-micro', 'text-ink-on-sky')).toBe(
            'text-label-micro text-ink-on-sky',
        );
    });

    it('treats the label tiers as font sizes that override each other', () => {
        expect(cn('text-label-small', 'text-label-micro')).toBe(
            'text-label-micro',
        );
        expect(cn('text-label-small', 'text-lg')).toBe('text-lg');
    });

    it('keeps display/headline/quote/stat scale tokens alongside a text color', () => {
        // These also bundle no color (see StatTile's `lg` size, which combined
        // text-stat with a color before this fix and silently lost text-stat).
        expect(cn('text-display-2xl', 'text-foreground')).toBe(
            'text-display-2xl text-foreground',
        );
        expect(cn('text-headline-sm', 'text-cream')).toBe(
            'text-headline-sm text-cream',
        );
        expect(cn('text-quote-lg', 'text-text-2')).toBe(
            'text-quote-lg text-text-2',
        );
        expect(cn('text-stat', 'text-cream')).toBe('text-stat text-cream');
    });
});

describe('container width utilities', () => {
    // These lost to CSS source order before being registered, so a caller's
    // narrower cap silently did nothing.
    it('lets a caller override the PageContainer column width', () => {
        expect(
            cn('min-[900px]:max-w-column', 'min-[900px]:max-w-[520px]'),
        ).toBe('min-[900px]:max-w-[520px]');
        expect(
            cn('min-[1280px]:max-w-column-wide', 'min-[1280px]:max-w-[520px]'),
        ).toBe('min-[1280px]:max-w-[520px]');
    });

    it('still resolves the named container widths against each other', () => {
        expect(cn('max-w-page', 'max-w-page-2xl')).toBe('max-w-page-2xl');
    });

    it('keeps a width at a different breakpoint', () => {
        expect(cn('max-w-column', 'min-[1280px]:max-w-column-wide')).toBe(
            'max-w-column min-[1280px]:max-w-column-wide',
        );
    });
});
