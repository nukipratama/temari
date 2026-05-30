import { cva } from 'class-variance-authority';

/**
 * class-variance-authority variant matrices for the shared UI primitives.
 * Each export mirrors the inline `Record`-table lookups the components used to
 * carry, so a component becomes `cn(variants({ … }), className)`. Pair with the
 * {@link ./cn} merge helper so caller `className` overrides win.
 *
 * Plain data maps that are NOT style-variant matrices (mood → face/label/fill in
 * {@link ./mood}, icon-tile tones in {@link ./tones}) intentionally stay as
 * `Record` lookups and are not folded in here.
 */

/** Card surface tone + padding. Mirrors TONE_CLASS / PADDING_CLASS in components/ui/Card.tsx. */
export const cardVariants = cva('', {
    variants: {
        tone: {
            cream: 'rounded-2xl bg-cream',
            'cream-deep': 'rounded-2xl bg-cream-deep',
            'sky-glass': 'rounded-2xl border border-cream/[0.12] bg-cream/[0.06] backdrop-blur',
            empty: 'rounded-2xl border border-dashed border-cream-deep bg-cream/40',
        },
        padding: {
            none: '',
            sm: 'px-4 py-3.5',
            md: 'px-5 py-5',
            lg: 'px-6 py-6',
        },
    },
    defaultVariants: {
        tone: 'cream',
        padding: 'md',
    },
});

/**
 * Pill button tone + size, with an `onSky` compound that flips the `ghost`
 * tone to its cream-on-sky variant. Mirrors TONE_CLASS + size ternary +
 * GHOST_ON_SKY in components/ui/PillButton.tsx.
 */
export const pillButtonVariants = cva(
    'inline-flex items-center gap-2 rounded-full font-sans font-medium transition',
    {
        variants: {
            tone: {
                horizon: 'bg-horizon text-sky hover:bg-horizon-deep',
                sky: 'bg-sky text-cream hover:bg-sky-deep',
                ghost: 'bg-transparent text-ink border-[1.5px] border-ink/[0.18] hover:border-ink-2',
            },
            size: {
                sm: 'px-3.5 py-2 text-[13px]',
                md: 'px-[22px] py-3 text-sm',
            },
            onSky: {
                true: '',
                false: '',
            },
        },
        compoundVariants: [
            {
                tone: 'ghost',
                onSky: true,
                class: 'bg-transparent text-cream border-[1.5px] border-cream/30 hover:border-cream/60',
            },
        ],
        defaultVariants: {
            tone: 'horizon',
            size: 'md',
            onSky: false,
        },
    },
);

/** Chip tone + size. Mirrors TONE_CLASS + size ternary in components/ui/Chip.tsx. */
export const chipVariants = cva(
    'inline-flex items-center gap-1 whitespace-nowrap rounded-full text-label-micro font-semibold tracking-[0.08em]',
    {
        variants: {
            tone: {
                neutral: 'bg-ink/[0.06] text-ink-2',
                horizon: 'bg-horizon/[0.18] text-horizon-deep',
                leaf: 'bg-leaf/[0.18] text-leaf',
                sky: 'bg-sky/[0.08] text-sky',
                onSky: 'bg-cream/10 text-cream/80',
            },
            size: {
                sm: 'px-[9px] py-[3px] text-[10px]',
                md: 'px-[11px] py-[5px] text-[11px]',
            },
        },
        defaultVariants: {
            tone: 'neutral',
            size: 'sm',
        },
    },
);

/**
 * Rarity → border + corner-flag scale. Mirrors RARITY_BORDER (lib/runcard.ts)
 * plus the per-component flag treatments in card/Kartu.tsx (RARITY_FLAG_BG)
 * and card/KartuMini.tsx (RARITY_CORNER). Exposed as three slots so each card
 * surface can opt into the part it renders.
 */
export const rarityVariants = {
    border: cva('', {
        variants: {
            rarity: {
                common: 'border-rarity-common',
                uncommon: 'border-rarity-uncommon',
                rare: 'border-rarity-rare',
                epic: 'border-rarity-epic',
                legendary: 'border-rarity-legendary',
            },
        },
        defaultVariants: { rarity: 'epic' },
    }),
    flag: cva('', {
        variants: {
            rarity: {
                common: 'bg-rarity-common text-cream',
                uncommon: 'bg-rarity-uncommon text-cream',
                rare: 'bg-rarity-rare text-cream',
                epic: 'bg-rarity-epic text-ink',
                legendary: 'bg-rarity-legendary text-ink',
            },
        },
        defaultVariants: { rarity: 'epic' },
    }),
    corner: cva('', {
        variants: {
            rarity: {
                common: 'border-t-rarity-common',
                uncommon: 'border-t-rarity-uncommon',
                rare: 'border-t-rarity-rare',
                epic: 'border-t-rarity-epic',
                legendary: 'border-t-rarity-legendary',
            },
        },
        defaultVariants: { rarity: 'epic' },
    }),
};
