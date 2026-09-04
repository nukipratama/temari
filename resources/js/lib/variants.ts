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
 * itself, the same card mounted on a dark sky panel, and the empty state, which
 * the prototype draws as an ordinary card on the heavier border rather than as
 * a dashed placeholder (see T2/T3). Padding names the `--pad-*` role it wants
 * rather than a number.
 */
export const cardVariants = cva('rounded-md', {
    variants: {
        tone: {
            card: 'border border-border bg-card shadow-e1',
            sky: 'border border-sky bg-sky text-cream shadow-e2',
            onSky: 'border border-cream/[0.12] bg-cream/[0.06] backdrop-blur',
            empty: 'border border-border-strong bg-card shadow-e1',
            // Temari's voice: the card gains a heavier accent-mixed edge and a
            // horizon halo so narration reads as spoken, not tabulated.
            narration:
                'border-[1.5px] border-horizon-ink/45 bg-card shadow-e1 ring-3 ring-horizon/15',
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
                ghost: 'bg-transparent text-foreground border-[1.5px] border-ink/[0.18] hover:border-ink-2',
                outline:
                    'bg-card border-[1.5px] border-border text-text-2 hover:border-ink-3 hover:text-foreground',
            },
            size: {
                sm: 'px-3.5 py-2 text-[0.8125rem]',
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
    'pad-chip inline-flex items-center gap-1 whitespace-nowrap rounded-full font-semibold tracking-[0.08em]',
    {
        variants: {
            tone: {
                neutral: 'bg-ink/[0.06] text-text-2',
                horizon: 'bg-horizon/[0.18] text-horizon-ink',
                sky: 'bg-sky/[0.08] text-sky',
                onSky: 'bg-cream/10 text-cream/80',
            },
            size: {
                sm: 'text-[0.6875rem]',
                md: 'text-[0.75rem]',
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
 * by the PRs progression tabs and the ShareCardModal theme picker. One source
 * of truth for radius/size/state. Filter rows that need a bordered or tinted
 * treatment (history range + mood, AiUsage presets) stay hand-rolled.
 */
export const toggleButtonVariants = cva(
    'inline-flex items-center justify-center rounded-full font-sans font-medium transition focus-ring',
    {
        variants: {
            size: {
                sm: 'px-3 py-1.5 text-[0.75rem]',
                md: 'px-4 py-2 text-sm',
            },
            selected: {
                true: 'bg-foreground text-background',
                false: 'bg-muted text-text-2 hover:bg-muted/70',
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
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-full transition text-text-2 hover:bg-ink/[0.06] hover:text-foreground focus-ring',
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
 * Bordered pill — the hairline-outlined counterpart to
 * {@link toggleButtonVariants}'s solid fill, for a selectable filter (race
 * distance presets, the PRs progression tabs) or an inline row action (the
 * Plan tab's per-day controls). Gold-on-paper is `horizon-ink`, never the
 * `horizon-deep` CTA fill.
 */
export const outlineChipVariants = cva(
    'focus-ring inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 py-1.5 text-label-micro transition',
    {
        variants: {
            selected: {
                true: 'border-horizon bg-horizon/[0.18] text-horizon-ink',
                false: 'border-border text-text-3 hover:border-horizon/60 hover:text-foreground',
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
    'focus-ring w-full border border-border bg-background rounded-sm text-foreground',
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
            'ink-2': 'text-text-2',
            'ink-3': 'text-text-3',
            horizon: 'text-horizon',
            'horizon-ink': 'text-horizon-ink',
            'icon-accent': 'text-icon-accent',
            'ink-on-sky': 'text-ink-on-sky',
            cream: 'text-cream',
        },
    },
});

/**
 * Rarity → border + flag + corner scale, the one source of truth for the card
 * surfaces. `border` backs card/Card.tsx and card/RunCardMini.tsx; `flag` and
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
                common: 'bg-rarity-common text-ink-on-rarity',
                uncommon: 'bg-rarity-uncommon text-ink-on-rarity',
                rare: 'bg-rarity-rare text-ink-on-rarity',
                epic: 'bg-rarity-epic text-ink-on-rarity',
                legendary: 'bg-rarity-legendary text-ink-on-rarity',
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
