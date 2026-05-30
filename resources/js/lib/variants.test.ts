import { describe, expect, it } from 'vitest';
import {
    cardVariants,
    chipVariants,
    pillButtonVariants,
    rarityVariants,
} from './variants';

describe('cardVariants', () => {
    it('applies the default cream tone + md padding', () => {
        const cls = cardVariants();
        expect(cls).toContain('bg-cream');
        expect(cls).toContain('py-5');
    });

    it.each([
        ['cream', 'bg-cream'],
        ['cream-deep', 'bg-cream-deep'],
        ['sky-glass', 'backdrop-blur'],
        ['empty', 'border-dashed'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(cardVariants({ tone })).toContain(expected);
    });

    it('emits no padding utilities for padding="none"', () => {
        expect(cardVariants({ padding: 'none' })).not.toContain('py-');
    });

    it('honours padding="sm"', () => {
        expect(cardVariants({ padding: 'sm' })).toContain('py-3.5');
    });
});

describe('pillButtonVariants', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-sky'],
        ['ghost', 'border-ink/[0.18]'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(pillButtonVariants({ tone })).toContain(expected);
    });

    it('uses sm sizing when size="sm"', () => {
        expect(pillButtonVariants({ size: 'sm' })).toContain('text-[13px]');
    });

    it('flips ghost to the on-sky variant via the onSky compound', () => {
        const cls = pillButtonVariants({ tone: 'ghost', onSky: true });
        expect(cls).toContain('text-cream');
        expect(cls).toContain('border-cream/30');
    });

    it('does not apply the on-sky compound to non-ghost tones', () => {
        const cls = pillButtonVariants({ tone: 'horizon', onSky: true });
        expect(cls).not.toContain('border-cream/30');
    });
});

describe('chipVariants', () => {
    it.each([
        ['neutral', 'text-ink-2'],
        ['horizon', 'text-horizon-deep'],
        ['leaf', 'text-leaf'],
        ['sky', 'text-sky'],
        ['onSky', 'text-cream/80'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(chipVariants({ tone })).toContain(expected);
    });

    it('adopts the text-label-micro utility in its base', () => {
        expect(chipVariants()).toContain('text-label-micro');
    });

    it('uses md sizing when size="md"', () => {
        expect(chipVariants({ size: 'md' })).toContain('text-[11px]');
    });
});

describe('rarityVariants', () => {
    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] as const)(
        'maps rarity %s to a border token',
        (rarity) => {
            expect(rarityVariants.border({ rarity })).toContain(`border-rarity-${rarity}`);
        },
    );

    it('maps rarity to a flag background + readable text tone', () => {
        expect(rarityVariants.flag({ rarity: 'legendary' })).toContain('bg-rarity-legendary');
        expect(rarityVariants.flag({ rarity: 'legendary' })).toContain('text-ink');
        expect(rarityVariants.flag({ rarity: 'common' })).toContain('text-cream');
    });

    it('maps rarity to a top-border corner flag', () => {
        expect(rarityVariants.corner({ rarity: 'rare' })).toContain('border-t-rarity-rare');
    });

    it('defaults to epic across all three slots', () => {
        expect(rarityVariants.border()).toContain('border-rarity-epic');
        expect(rarityVariants.flag()).toContain('bg-rarity-epic');
        expect(rarityVariants.corner()).toContain('border-t-rarity-epic');
    });
});
