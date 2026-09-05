import { describe, expect, it } from 'vitest';

import {
    cardVariants,
    chipVariants,
    iconButtonVariants,
    inputVariants,
    outlineChipVariants,
    pillButtonVariants,
    rarityVariants,
    toggleButtonVariants,
} from './variants';

/** Split into class tokens, so `toContain` matches a whole class, not a prefix of one. */
const tokens = (cls: string) => cls.split(' ');

describe('cardVariants', () => {
    it('applies the one card surface, radius, elevation and pad role by default', () => {
        const cls = tokens(cardVariants());
        expect(cls).toContain('bg-card');
        expect(cls).toContain('border-border');
        expect(cls).toContain('rounded-md');
        expect(cls).toContain('shadow-e1');
        expect(cls).toContain('pad-card');
    });

    it.each([
        ['card', 'bg-card'],
        ['sky', 'bg-sky'],
        ['onSky', 'backdrop-blur'],
        ['empty', 'border-border-strong'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(tokens(cardVariants({ tone }))).toContain(expected);
    });

    it('keeps every tone on the same radius', () => {
        for (const tone of ['card', 'sky', 'onSky', 'empty'] as const) {
            expect(tokens(cardVariants({ tone }))).toContain('rounded-md');
        }
    });

    it('emits no padding utility for padding="none"', () => {
        expect(cardVariants({ padding: 'none' })).not.toContain('pad-');
    });

    it('names a --pad-* role rather than a number', () => {
        expect(tokens(cardVariants({ padding: 'panel' }))).toContain(
            'pad-panel',
        );
        expect(tokens(cardVariants({ padding: 'hero' }))).toContain('pad-hero');
    });
});

describe('pillButtonVariants', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-sky'],
        ['ghost', 'border-foreground/20'],
        ['outline', 'border-border'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(tokens(pillButtonVariants({ tone }))).toContain(expected);
    });

    it('gives the outline tone a card fill with an ink-2 label', () => {
        const cls = tokens(pillButtonVariants({ tone: 'outline' }));
        expect(cls).toContain('bg-card');
        expect(cls).toContain('text-text-2');
        expect(cls).toContain('hover:border-foreground/40');
    });

    it('uses sm sizing when size="sm"', () => {
        expect(tokens(pillButtonVariants({ size: 'sm' }))).toContain(
            'text-[0.8125rem]',
        );
    });

    it('carries the shared focus-ring in its base', () => {
        expect(tokens(pillButtonVariants())).toContain('focus-ring');
    });

    it('flips ghost to the on-sky variant via the onSky compound', () => {
        const cls = tokens(pillButtonVariants({ tone: 'ghost', onSky: true }));
        expect(cls).toContain('text-cream');
        expect(cls).toContain('border-cream/30');
    });

    it('does not apply the on-sky compound to non-ghost tones', () => {
        const cls = tokens(
            pillButtonVariants({ tone: 'horizon', onSky: true }),
        );
        expect(cls).not.toContain('border-cream/30');
    });
});

describe('chipVariants', () => {
    it.each([
        ['neutral', 'text-text-2'],
        ['horizon', 'text-horizon-ink'],
        ['sky', 'text-sky'],
        ['onSky', 'text-cream/80'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(tokens(chipVariants({ tone }))).toContain(expected);
    });

    it('leaves the label tier to the caller, since a size variant would strip it', () => {
        // Both text-label-micro and the size variants live in tailwind-merge's
        // font-size group (see lib/cn.ts), so a base that declared the utility
        // had it dropped at every call site. A caller passes it via className,
        // which is merged last and therefore wins.
        expect(tokens(chipVariants())).not.toContain('text-label-micro');
        expect(tokens(chipVariants())).toContain('text-[0.6875rem]');
    });

    it('uses md sizing when size="md"', () => {
        expect(tokens(chipVariants({ size: 'md' }))).toContain(
            'text-[0.75rem]',
        );
    });
});

describe('toggleButtonVariants', () => {
    // Both states are ground-reactive on purpose: a fixed cream-deep fill under
    // reactive text rendered near-white on near-white on the dark ground.
    it('renders the selected state as an inverted pill', () => {
        const cls = tokens(toggleButtonVariants({ selected: true }));
        expect(cls).toContain('bg-foreground');
        expect(cls).toContain('text-background');
    });

    it('renders the unselected state on muted', () => {
        const cls = tokens(toggleButtonVariants({ selected: false }));
        expect(cls).toContain('bg-muted');
        expect(cls).toContain('text-text-2');
    });

    it('carries the shared focus-ring in its base', () => {
        expect(tokens(toggleButtonVariants())).toContain('focus-ring');
    });

    it('uses md sizing when size="md"', () => {
        expect(tokens(toggleButtonVariants({ size: 'md' }))).toContain(
            'text-sm',
        );
    });
});

describe('iconButtonVariants', () => {
    it('defaults to a sm square hit target with a focus-ring', () => {
        const cls = tokens(iconButtonVariants());
        expect(cls).toContain('h-10');
        expect(cls).toContain('w-10');
        expect(cls).toContain('focus-ring');
    });

    it('enforces a >=44px tap target floor regardless of size', () => {
        for (const size of ['sm', 'md'] as const) {
            const cls = tokens(iconButtonVariants({ size }));
            expect(cls).toContain('min-h-11');
            expect(cls).toContain('min-w-11');
        }
    });

    it('flips to the cream-on-sky treatment via onSky', () => {
        const cls = tokens(iconButtonVariants({ onSky: true }));
        expect(cls).toContain('text-cream/80');
        expect(cls).toContain('hover:text-cream');
    });
});

describe('rarityVariants', () => {
    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] as const)(
        'maps rarity %s to a border token',
        (rarity) => {
            expect(tokens(rarityVariants.border({ rarity }))).toContain(
                `border-rarity-${rarity}`,
            );
        },
    );

    it.each([
        ['common', 'bg-rarity-common'],
        ['uncommon', 'bg-rarity-uncommon'],
        ['rare', 'bg-rarity-rare'],
        ['epic', 'bg-rarity-epic'],
        ['legendary', 'bg-rarity-legendary'],
    ] as const)(
        'flags rarity %s with its fill and the on-fill label tone',
        (rarity, fill) => {
            expect(tokens(rarityVariants.flag({ rarity }))).toContain(fill);
            expect(tokens(rarityVariants.flag({ rarity }))).toContain(
                'text-ink-on-rarity',
            );
        },
    );

    it('maps rarity to a top-border corner flag', () => {
        expect(tokens(rarityVariants.corner({ rarity: 'rare' }))).toContain(
            'border-t-rarity-rare',
        );
    });

    it('defaults to epic across all three slots', () => {
        expect(tokens(rarityVariants.border())).toContain('border-rarity-epic');
        expect(tokens(rarityVariants.flag())).toContain('bg-rarity-epic');
        expect(tokens(rarityVariants.corner())).toContain(
            'border-t-rarity-epic',
        );
    });
});

describe('outlineChipVariants', () => {
    it('draws the unselected state as a hairline outline on the meta tier', () => {
        const cls = tokens(outlineChipVariants());
        expect(cls).toContain('border-border');
        expect(cls).toContain('text-text-3');
        expect(cls).toContain('rounded-full');
        expect(cls).toContain('focus-ring');
    });

    it('carries gold as text on the -ink member, never the CTA fill', () => {
        const cls = tokens(outlineChipVariants({ selected: true }));
        expect(cls).toContain('border-horizon');
        expect(cls).toContain('text-horizon-ink');
    });

    it('keeps both states on the same geometry', () => {
        for (const selected of [true, false]) {
            const cls = tokens(outlineChipVariants({ selected }));
            expect(cls).toContain('px-3');
            expect(cls).toContain('py-1.5');
        }
    });
});

describe('inputVariants', () => {
    it('uses the radius scale’s input corner, not a card or pill corner', () => {
        const cls = tokens(inputVariants());
        expect(cls).toContain('rounded-sm');
        expect(cls).not.toContain('rounded-md');
        expect(cls).not.toContain('rounded-full');
    });

    it('carries the shared field surface and focus-ring', () => {
        const cls = tokens(inputVariants());
        expect(cls).toContain('bg-background');
        expect(cls).toContain('border-border');
        expect(cls).toContain('focus-ring');
    });

    it('tightens padding for the inline sm field', () => {
        expect(tokens(inputVariants({ size: 'sm' }))).toContain('px-2.5');
        expect(tokens(inputVariants({ size: 'sm' }))).toContain('py-1');
        expect(tokens(inputVariants({ size: 'md' }))).toContain('px-3');
        expect(tokens(inputVariants({ size: 'md' }))).toContain('py-2');
    });
});

describe('inline control row geometry', () => {
    it('lands the sm field and the outline chip on the same min height', () => {
        expect(tokens(inputVariants({ size: 'sm' }))).toContain('min-h-8');
        expect(tokens(outlineChipVariants())).toContain('min-h-8');
    });
});
