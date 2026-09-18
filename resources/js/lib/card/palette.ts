import type { Rarity } from '@/types/inertia';

/**
 * The fixed export palette. A shared image has no ground to follow, so every
 * value here is the literal light-ground token from `resources/css/app.css`
 * rather than a ground-reactive one — the popup chrome follows the ground, the
 * print never does.
 */
export const SKY = '#171f28';
export const SKY_DEEP = '#0b1017';
export const HORIZON = '#ade047';
export const HORIZON_INK = '#546d23';
export const CREAM = '#f1f5f8';
export const CREAM_DEEP = '#e2e8ee';
export const SURFACE_ELEV = '#f8fbfe';
export const INK = '#16181b';
export const INK_2 = '#34373c';
export const INK_3 = '#60666d';
export const INK_ON_SKY = '#9c9ea7';
export const LINE = '#bfc5cc';
export const LINE_STRONG = '#b2b9c2';
export const LEAF = '#2f8f63';
export const LEAF_INK = '#226748';
export const EMBER = '#b23a4f';
export const EMBER_INK = '#9b3245';
export const CITRUS = '#c9971f';

/** The broadsheet's warm PR field: sky-deep pulled toward the citrus hue. */
export const PR_GROUND = '#140d16';

/**
 * The `-ink` tier of each rarity family, mirrored from the light ground's
 * `--color-rarity-*-ink` tokens. An exported card has no ground to follow, so
 * it always takes the light value — the only member of the pair allowed to
 * carry text on paper.
 */
export const RARITY_INK_HEX: Record<Rarity, string> = {
    common: '#5f6671',
    uncommon: '#1f7434',
    rare: '#2463be',
    epic: '#8543c4',
    legendary: '#865b13',
};

/** Vendored italic-only; always paired with `italic: true`. */
export const DISPLAY = 'Fraunces';
export const SANS = 'Plus Jakarta Sans';
export const MONO = 'JetBrains Mono';

/** Dark ink on a light fill, cream on a dark one, by relative luminance. */
export function readableInk(hex: string): string {
    const channels = [1, 3, 5].map((offset) => {
        const value = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return luminance > 0.42 ? INK : CREAM;
}
