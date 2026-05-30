/**
 * Canonical Daybreak hex values for use inside Chart.js / inline-SVG, which
 * cannot read CSS `var(--color-*)` tokens off a `<canvas>`. These MUST mirror
 * the `@theme` block in [resources/css/app.css](../../css/app.css); treat that
 * file as source of truth and keep this bridge in sync when a token moves.
 *
 * Use the named exports (never loose hex) so a chart series reads as
 * "ember" / "leaf" at the call site and recolors with the palette.
 */
export const DAYBREAK = {
    leaf: '#6b8e6f',
    leafDeep: '#4f6c54',
    ember: '#c4623f',
    emberDeep: '#a35030',
    horizon: '#e8a076',
    horizonDeep: '#d08a60',
    mumet: '#7b5bb6',
    citrus: '#d9b23a',
    citrusDeep: '#b8941e',
    stone: '#8e8579',
    sky: '#1f2747',
    skyDeep: '#161b33',
    ink: '#1a1812',
} as const;

export type DaybreakColor = keyof typeof DAYBREAK;

/**
 * HR-zone fills. HR zones follow a sports-convention cool→warm ramp (green easy
 * → red max) rather than the Daybreak brand hues, but they live here as NAMED
 * tokens so they are not loose hex scattered in the component.
 */
export const hrZone = {
    Z1: '#5fb088',
    Z2: '#2f956a',
    Z3: '#d99a1a',
    Z4: '#c46f1c',
    Z5: '#b8302f',
} as const;

export type HrZoneKey = keyof typeof hrZone;
