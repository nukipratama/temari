import { describe, expect, it } from 'vitest';

import {
    cardVariants,
    chipVariants,
    filterOptionVariants,
    iconButtonVariants,
    inputVariants,
    outlineChipVariants,
    pillButtonVariants,
    rarityVariants,
    toggleButtonVariants,
} from './variants';

describe('cardVariants', () => {
    it('applies the one card surface, radius, elevation and pad role by default', () => {
        const cls = cardVariants();
        expect(cls).toContain('bg-surface-card');
        expect(cls).toContain('border-line');
        expect(cls).toContain('rounded-md');
        expect(cls).toContain('shadow-e1');
        expect(cls).toContain('pad-card');
    });

    it.each([
        ['card', 'bg-surface-card'],
        ['onSky', 'backdrop-blur'],
        ['empty', 'border-dashed'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(cardVariants({ tone })).toContain(expected);
    });

    it('keeps every tone on the same radius', () => {
        for (const tone of ['card', 'onSky', 'empty'] as const) {
            expect(cardVariants({ tone })).toContain('rounded-md');
        }
    });

    it('emits no padding utility for padding="none"', () => {
        expect(cardVariants({ padding: 'none' })).not.toContain('pad-');
    });

    it('names a --pad-* role rather than a number', () => {
        expect(cardVariants({ padding: 'panel' })).toContain('pad-panel');
        expect(cardVariants({ padding: 'hero' })).toContain('pad-hero');
    });
});

describe('pillButtonVariants', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-sky'],
        ['ghost', 'border-ink/[0.18]'],
        ['outline', 'border-cream-deep'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(pillButtonVariants({ tone })).toContain(expected);
    });

    it('gives the outline tone a cream fill with an ink-2 label', () => {
        const cls = pillButtonVariants({ tone: 'outline' });
        expect(cls).toContain('bg-cream');
        expect(cls).toContain('text-ink-2');
        expect(cls).toContain('hover:border-ink-3');
    });

    it('uses sm sizing when size="sm"', () => {
        expect(pillButtonVariants({ size: 'sm' })).toContain('text-[13px]');
    });

    it('carries the shared focus-ring in its base', () => {
        expect(pillButtonVariants()).toContain('focus-ring');
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
        ['horizon', 'text-horizon-ink'],
        ['leaf', 'text-leaf-ink'],
        ['sky', 'text-sky'],
        ['onSky', 'text-cream/80'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(chipVariants({ tone })).toContain(expected);
    });

    it('adopts the text-label-micro utility in its base', () => {
        expect(chipVariants()).toContain('text-label-micro');
    });

    it('uses md sizing when size="md"', () => {
        expect(chipVariants({ size: 'md' })).toContain('text-[12px]');
    });
});

describe('toggleButtonVariants', () => {
    it('renders the selected state as a sky pill', () => {
        const cls = toggleButtonVariants({ selected: true });
        expect(cls).toContain('bg-sky');
        expect(cls).toContain('text-cream');
    });

    it('renders the unselected state on cream-deep', () => {
        const cls = toggleButtonVariants({ selected: false });
        expect(cls).toContain('bg-cream-deep');
        expect(cls).toContain('text-ink-2');
    });

    it('carries the shared focus-ring in its base', () => {
        expect(toggleButtonVariants()).toContain('focus-ring');
    });

    it('uses md sizing when size="md"', () => {
        expect(toggleButtonVariants({ size: 'md' })).toContain('text-sm');
    });
});

describe('iconButtonVariants', () => {
    it('defaults to a sm square hit target with a focus-ring', () => {
        const cls = iconButtonVariants();
        expect(cls).toContain('h-10');
        expect(cls).toContain('w-10');
        expect(cls).toContain('focus-ring');
    });

    it('enforces a >=44px tap target floor regardless of size', () => {
        expect(iconButtonVariants({ size: 'sm' })).toContain('min-h-11');
        expect(iconButtonVariants({ size: 'sm' })).toContain('min-w-11');
        expect(iconButtonVariants({ size: 'md' })).toContain('min-h-11');
        expect(iconButtonVariants({ size: 'md' })).toContain('min-w-11');
    });

    it('flips to the cream-on-sky treatment via onSky', () => {
        const cls = iconButtonVariants({ onSky: true });
        expect(cls).toContain('text-cream/80');
        expect(cls).toContain('hover:text-cream');
    });
});

describe('filterOptionVariants', () => {
    it('gives an active row a bold sky tint, on top of the shared active tint', () => {
        const cls = filterOptionVariants({ layout: 'row', active: true });
        expect(cls).toContain('bg-sky/10');
        expect(cls).toContain('text-sky');
        expect(cls).toContain('font-semibold');
    });

    it('leaves an inactive row plain', () => {
        const cls = filterOptionVariants({ layout: 'row', active: false });
        expect(cls).toContain('text-ink');
        expect(cls).toContain('hover:bg-surface-warm');
        expect(cls).not.toContain('font-semibold');
    });

    it('does not bold an active mood tile, unlike an active row', () => {
        const cls = filterOptionVariants({ layout: 'mood', active: true });
        expect(cls).toContain('bg-sky/10');
        expect(cls).toContain('text-sky');
        expect(cls).not.toContain('font-semibold');
    });

    it('stretches a row full width but not a mood tile', () => {
        expect(filterOptionVariants({ layout: 'row' })).toContain('w-full');
        expect(filterOptionVariants({ layout: 'mood' })).not.toContain(
            'w-full',
        );
    });

    it('carries the shared focus-ring in its base', () => {
        expect(filterOptionVariants()).toContain('focus-ring');
    });
});

describe('rarityVariants', () => {
    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] as const)(
        'maps rarity %s to a border token',
        (rarity) => {
            expect(rarityVariants.border({ rarity })).toContain(
                `border-rarity-${rarity}`,
            );
        },
    );

    it('maps rarity to a flag background + readable text tone', () => {
        expect(rarityVariants.flag({ rarity: 'legendary' })).toContain(
            'bg-rarity-legendary',
        );
        expect(rarityVariants.flag({ rarity: 'legendary' })).toContain(
            'text-ink',
        );
        expect(rarityVariants.flag({ rarity: 'common' })).toContain(
            'text-cream',
        );
    });

    it('maps rarity to a top-border corner flag', () => {
        expect(rarityVariants.corner({ rarity: 'rare' })).toContain(
            'border-t-rarity-rare',
        );
    });

    it('defaults to epic across all three slots', () => {
        expect(rarityVariants.border()).toContain('border-rarity-epic');
        expect(rarityVariants.flag()).toContain('bg-rarity-epic');
        expect(rarityVariants.corner()).toContain('border-t-rarity-epic');
    });
});

describe('outlineChipVariants', () => {
    it('draws the unselected state as a hairline outline on the meta tier', () => {
        const cls = outlineChipVariants();
        expect(cls).toContain('border-line');
        expect(cls).toContain('text-ink-3');
        expect(cls).toContain('rounded-full');
        expect(cls).toContain('focus-ring');
    });

    it('carries gold as text on the -ink member, never the CTA fill', () => {
        const cls = outlineChipVariants({ selected: true });
        expect(cls).toContain('border-horizon');
        expect(cls).toContain('text-horizon-ink');
        expect(cls).not.toContain('text-horizon-deep');
    });

    it('keeps both states on the same geometry', () => {
        for (const selected of [true, false]) {
            expect(outlineChipVariants({ selected })).toContain('px-3 py-1.5');
        }
    });
});

describe('inputVariants', () => {
    it('uses the radius scale’s input corner, not a card or pill corner', () => {
        const cls = inputVariants();
        expect(cls).toContain('rounded-sm');
        expect(cls).not.toContain('rounded-md');
        expect(cls).not.toContain('rounded-full');
    });

    it('carries the shared field surface and focus-ring', () => {
        const cls = inputVariants();
        expect(cls).toContain('bg-surface');
        expect(cls).toContain('border-line');
        expect(cls).toContain('focus-ring');
    });

    it('tightens padding for the inline sm field', () => {
        expect(inputVariants({ size: 'sm' })).toContain('px-2.5 py-1');
        expect(inputVariants({ size: 'md' })).toContain('px-3 py-2');
    });
});

describe('inline control row geometry', () => {
    it('lands the sm field and the outline chip on the same min height', () => {
        expect(inputVariants({ size: 'sm' })).toContain('min-h-8');
        expect(outlineChipVariants()).toContain('min-h-8');
    });
});
