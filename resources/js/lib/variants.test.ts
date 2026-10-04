import { describe, expect, it } from 'vitest';

import {
    chipVariants,
    iconButtonVariants,
    inputVariants,
    outlineChipVariants,
    pillButtonVariants,
    rarityVariants,
} from './variants';

/** Split into class tokens, so `toContain` matches a whole class, not a prefix of one. */
const tokens = (cls: string) => cls.split(' ');

describe('pillButtonVariants', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-foreground'],
        ['ghost', 'border-foreground/20'],
        ['outline', 'border-border'],
        ['danger', 'bg-ember-deep'],
    ] as const)('renders tone %s', (tone, expected) => {
        expect(tokens(pillButtonVariants({ tone }))).toContain(expected);
    });

    it('gives the outline tone a card fill with an ink-2 label', () => {
        const cls = tokens(pillButtonVariants({ tone: 'outline' }));
        expect(cls).toContain('bg-card');
        expect(cls).toContain('text-text-2');
        expect(cls).toContain('hover:border-foreground/40');
    });

    it('draws the compact muted action pill from tone="muted" size="xs"', () => {
        const cls = tokens(pillButtonVariants({ tone: 'muted', size: 'xs' }));
        expect(cls).toContain('bg-muted');
        expect(cls).toContain('text-foreground');
        expect(cls).toContain('hover:bg-accent');
        expect(cls).toContain('h-8');
        expect(cls).toContain('text-label-micro');
    });

    it('uses sm sizing when size="sm"', () => {
        expect(tokens(pillButtonVariants({ size: 'sm' }))).toContain('text-xs');
    });

    it('carries the shared focus-ring in its base', () => {
        expect(tokens(pillButtonVariants())).toContain('focus-ring');
    });
});

describe('chipVariants', () => {
    it.each([
        ['neutral', 'text-text-2'],
        ['horizon', 'text-horizon-ink'],
        ['positive', 'text-leaf-ink'],
        ['warning', 'text-ember-ink'],
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
        expect(tokens(chipVariants({ size: 'md' }))).toContain('text-xs');
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

    it('carries the shared press feedback', () => {
        expect(tokens(iconButtonVariants())).toContain('pressable');
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

    it('sits on the same 32px floor as the small input beside it', () => {
        expect(tokens(outlineChipVariants())).toContain('min-h-8');
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
