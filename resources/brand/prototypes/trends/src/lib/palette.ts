/**
 * Pewter's hex values for Chart.js, which draws to a <canvas> and cannot
 * resolve `var(--…)`. Mirrors :root in index.css.
 */
export const PEWTER = {
    cream: '#f1f5f8',
    creamDeep: '#e2e8ee',
    surfaceElev: '#f8fbfe',
    sky: '#171f28',
    sky2: '#26303d',
    horizon: '#ade047',
    horizonDeep: '#95c134',
    horizonInk: '#577024',
    ink: '#16181b',
    ink2: '#34373c',
    ink3: '#60666d',
    line: '#bfc5cc',
    stone: '#64686c',
    inkOnSky: '#9c9ea7',
    leaf: '#2f8f63',
    leafInk: '#277551',
    ember: '#b23a4f',
    citrus: '#c9971f',
    citrusInk: '#846314',
} as const;

/**
 * Series roles, not raw colours.
 *
 * Checked with the dataviz skill's validator against Pewter's own card ground
 * (#f1f5f8). Two results drove these choices:
 *
 *  - `horizon` (1.42:1) and `horizonDeep` (1.92:1) both fall under the 3:1
 *    non-text contrast floor, so Pewter's brand accent cannot carry a stroke on
 *    its own ground. `horizonInk` (4.5:1) is the strokeable step of that hue;
 *    `horizon` is kept for area fills, where it is a wash and not a line.
 *  - `horizonInk` vs `ink3` is only ΔE 11.5 in normal vision (floor is 15), so
 *    the reference series is `sky2` instead: ΔE 23.9 normal, 23.6 deutan.
 */
export const SERIES = {
    primary: PEWTER.horizonInk,
    primaryFill: PEWTER.horizon,
    reference: PEWTER.sky2,
    grid: `${PEWTER.ink3}1f`,
    axis: PEWTER.ink2,
} as const;

/** Reserved status ramp. Never a series colour, and never used without a word beside it. */
export const STATUS = {
    good: PEWTER.leafInk,
    watch: PEWTER.citrusInk,
    high: PEWTER.ember,
} as const;

export type StatusKey = keyof typeof STATUS;
