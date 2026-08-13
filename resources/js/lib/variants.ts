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

/**
 * The card system. One surface treatment — `surface-card` on a `line` border at
 * the `md` radius with the resting `e1` elevation — in three states: the card
 * itself, the same card mounted on a dark sky panel, and the dashed placeholder
 * that stands in for a card that has no content yet. Padding names the `--pad-*`
 * role it wants rather than a number.
 */
export const cardVariants = cva('rounded-md', {
    variants: {
        tone: {
            card: 'border border-line bg-surface-card shadow-e1',
            sky: 'border border-sky bg-sky text-cream shadow-e2',
            onSky: 'border border-cream/[0.12] bg-cream/[0.06] backdrop-blur',
            empty: 'border border-dashed border-line-strong bg-surface-card/40',
        },
        padding: {
            none: '',
            panel: 'pad-panel',
            card: 'pad-card',
            hero: 'pad-hero',
        },
    },
    defaultVariants: {
        tone: 'card',
        padding: 'card',
    },
});

/**
 * Pill button tone + size, with an `onSky` compound that flips the `ghost`
 * tone to its cream-on-sky variant. Mirrors TONE_CLASS + size ternary +
 * GHOST_ON_SKY in components/ui/PillButton.tsx.
 */
export const pillButtonVariants = cva(
    'pressable inline-flex items-center gap-2 rounded-full font-sans font-medium transition focus-ring disabled:pointer-events-none disabled:opacity-60',
    {
        variants: {
            tone: {
                horizon: 'bg-horizon text-sky hover:bg-horizon-deep',
                sky: 'bg-sky text-cream hover:bg-sky-deep',
                ghost: 'bg-transparent text-ink border-[1.5px] border-ink/[0.18] hover:border-ink-2',
                outline:
                    'bg-cream border-[1.5px] border-cream-deep text-ink-2 hover:border-ink-3 hover:text-ink',
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
            {
                // Primary pill on a dark (sky) panel: flip to a cream fill so it
                // keeps contrast — navy-on-navy would vanish.
                tone: 'sky',
                onSky: true,
                class: 'bg-cream text-sky hover:bg-cream-deep',
            },
        ],
        defaultVariants: {
            tone: 'sky',
            size: 'md',
            onSky: false,
        },
    },
);

/** Chip tone + size. Mirrors TONE_CLASS + size ternary in components/ui/Chip.tsx. */
export const chipVariants = cva(
    'pad-chip inline-flex items-center gap-1 whitespace-nowrap rounded-full text-label-micro font-semibold tracking-[0.08em]',
    {
        variants: {
            tone: {
                neutral: 'bg-ink/[0.06] text-ink-2',
                horizon: 'bg-horizon/[0.18] text-horizon-ink',
                leaf: 'bg-leaf/[0.18] text-leaf',
                sky: 'bg-sky/[0.08] text-sky',
                onSky: 'bg-cream/10 text-cream/80',
            },
            size: {
                sm: 'text-[11px]',
                md: 'text-[12px]',
            },
        },
        defaultVariants: {
            tone: 'neutral',
            size: 'sm',
        },
    },
);

/**
 * Segmented / toggle control — the solid-fill selected-vs-unselected pill used
 * by the Rekor progression tabs and the ShareCardModal theme picker. One source
 * of truth for radius/size/state. Filter rows that need a bordered or tinted
 * treatment (riwayat range + mood, AiUsage presets) stay hand-rolled.
 */
export const toggleButtonVariants = cva(
    'inline-flex items-center justify-center rounded-full font-sans font-medium transition focus-ring',
    {
        variants: {
            size: {
                sm: 'px-3 py-1.5 text-[12px]',
                md: 'px-4 py-2 text-sm',
            },
            selected: {
                true: 'bg-sky text-cream',
                false: 'bg-cream-deep text-ink-2 hover:bg-cream-deep/70',
            },
        },
        defaultVariants: {
            size: 'sm',
            selected: false,
        },
    },
);

/**
 * Icon button — square/round hit target for a bare icon (close ×, nav
 * arrows, modal dismiss). `onSky` flips it to the cream-on-dark treatment.
 */
export const iconButtonVariants = cva(
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full transition text-ink-2 hover:bg-ink/[0.06] hover:text-ink focus-ring',
    {
        variants: {
            size: {
                sm: 'h-10 w-10',
                md: 'h-10 w-10',
            },
            onSky: {
                true: 'text-cream/80 hover:bg-cream/10 hover:text-cream',
                false: '',
            },
        },
        defaultVariants: {
            size: 'sm',
            onSky: false,
        },
    },
);

/**
 * Filter-panel option row — the sky-tinted-vs-plain toggle shared by the
 * range links, distance/sort buttons, and mood buttons in RiwayatFilter.tsx.
 * `layout: 'row'` covers the full-width justify-between rows (range,
 * distance, sort); `layout: 'mood'` covers the two-column mood grid, which
 * doesn't stretch full width and carries its own gap + weight.
 */
export const filterOptionVariants = cva(
    'focus-ring flex min-h-11 items-center rounded-lg px-2 py-2 text-left text-xs transition',
    {
        variants: {
            layout: {
                row: 'w-full justify-between lg:text-sm',
                mood: 'gap-2 font-medium',
            },
            active: {
                true: 'bg-sky/10 text-sky',
                false: 'text-ink hover:bg-surface-warm',
            },
        },
        compoundVariants: [
            {
                layout: 'row',
                active: true,
                class: 'font-semibold',
            },
        ],
        defaultVariants: {
            layout: 'row',
            active: false,
        },
    },
);

/**
 * Bordered pill — the hairline-outlined counterpart to
 * {@link toggleButtonVariants}'s solid fill, for a selectable filter (race
 * distance presets, the Rekor progression tabs) or an inline row action (the
 * Plan tab's per-day controls). Gold-on-paper is `horizon-ink`, never the
 * `horizon-deep` CTA fill.
 */
export const outlineChipVariants = cva(
    'focus-ring inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 py-1.5 text-label-micro transition',
    {
        variants: {
            selected: {
                true: 'border-horizon bg-horizon/10 text-horizon-ink',
                false: 'border-line text-ink-3 hover:border-horizon/60 hover:text-ink',
            },
        },
        defaultVariants: { selected: false },
    },
);

/**
 * Text/number/date field. `rounded-sm` is the radius scale's input corner, so
 * a field never picks up a card's `md` or a pill's `full`. `sm` is the inline
 * field that sits in a row of controls, and shares `min-h-8` with
 * {@link outlineChipVariants} so the two line up: a coarse pointer forces
 * every field to 16px (see the iOS zoom note in app.css), which would
 * otherwise leave a field taller than the pills beside it.
 */
export const inputVariants = cva(
    'focus-ring w-full border border-line bg-surface rounded-sm text-ink',
    {
        variants: {
            size: {
                sm: 'min-h-8 px-2.5 py-1 text-sm',
                md: 'px-3 py-2 text-sm',
            },
        },
        defaultVariants: { size: 'md' },
    },
);

/** Eyebrow's type tier, one of the `.text-label-*` role utilities in app.css. */
export const eyebrowVariants = cva('', {
    variants: {
        token: {
            micro: 'text-label-micro',
            small: 'text-label-small',
            hero: 'text-label-hero',
        },
        tone: {
            'ink-2': 'text-ink-2',
            'ink-3': 'text-ink-3',
            horizon: 'text-horizon',
            'horizon-ink': 'text-horizon-ink',
            'ink-on-sky': 'text-ink-on-sky',
            cream: 'text-cream',
        },
    },
});

/**
 * Rarity → border + flag + corner scale, the one source of truth for the card
 * surfaces. `border` backs card/Kartu.tsx and card/KartuMini.tsx; `flag` and
 * `corner` are the remaining two slots a card surface can opt into.
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
