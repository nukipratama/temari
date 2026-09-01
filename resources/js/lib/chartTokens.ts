/**
 * Canonical palette hex values for use inside Chart.js / inline-SVG, which
 * cannot read CSS `var(--color-*)` tokens off a `<canvas>`. These MUST mirror
 * the `@theme` block in [resources/css/app.css](../../css/app.css); treat that
 * file as source of truth and keep this bridge in sync when a token moves.
 *
 * Use the named exports (never loose hex) so a chart series reads as
 * "ember" / "leaf" at the call site and recolors with the palette.
 */
export const PALETTE = {
    leaf: '#2f8f63',
    leafDeep: '#256f4d',
    ember: '#b23a4f',
    emberDeep: '#8d2c3d',
    horizon: '#ade047',
    horizonDeep: '#95c134',
    horizonInk: '#546d23',
    overloaded: '#6b3fa0',
    gassed: '#7a2030',
    chill: '#55488f',
    citrus: '#c9971f',
    citrusInk: '#7b5c13',
    stone: '#64686c',
    sky: '#171f28',
    skyDeep: '#0b1017',
    cream: '#f1f5f8',
    ink: '#16181b',
    ink2: '#34373c',
    ink3: '#60666d',
    surfaceElev: '#f8fbfe',
    line: '#bfc5cc',
} as const;

export type PaletteColor = keyof typeof PALETTE;

/**
 * Ground-dependent chart roles. Bold graphic fills/accents in {@link PALETTE}
 * above (a bar, a filled area, `horizon` used at full saturation) read fine
 * on either ground unchanged, matching how `--mood-*`/`--rarity-*` fills
 * don't migrate either. What genuinely needs a ground-reactive pair is
 * anything text-weight: grid lines, axis/legend labels, a muted
 * secondary-series stroke, the ink-safe `horizonInk` line several trend
 * panels use as their primary stroke (darkened specifically to hold up as a
 * *thin* mark on light paper — that darkening is exactly what makes it
 * disappear against a dark chart surface), a neutral marker outline, and the
 * "cutout" ring Chart.js point markers use against the chart's own surface.
 * Values here mirror the light/dark declarations of `--color-text-2`/
 * `-text-3`/`-card`/`-border` in resources/css/app.css (kept as literal
 * hex/rgba, not `var()`, per the same constraint as PALETTE above);
 * `grid`/`tick` additionally match the frozen prototype's `CHART_PALETTE`.
 */
export const CHART_GROUND = {
    light: {
        grid: 'rgba(22,24,27,.08)',
        tick: '#34373c', // = text-2 on light
        secondaryLine: '#60666d', // = text-3 on light — the Fatigue/ATL stroke
        pointBorder: '#f1f5f8', // = card on light
        line: PALETTE.horizonInk, // primary accent stroke, ink-safe on light
        border: '#bfc5cc', // = border on light — neutral marker outlines
    },
    dark: {
        grid: 'rgba(241,245,248,.10)',
        tick: '#c1c2c8', // = text-2 on dark
        secondaryLine: '#9c9ea7', // = text-3 on dark
        pointBorder: '#171f28', // = card on dark
        line: PALETTE.horizon, // raw vivid lime — horizonInk is too dark to read here
        border: '#4d5560', // = border on dark
    },
} as const;

/**
 * HR-zone fills. Z1 (recovery / warm-up) is a bright cool teal so the ramp reads
 * cool→warm: teal easy → green → amber → orange → red. They live here as NAMED
 * tokens so they are not loose hex scattered in the component.
 */
export const hrZone = {
    Z1: '#35c6da',
    Z2: '#2f956a',
    Z3: '#d99a1a',
    Z4: '#c46f1c',
    Z5: '#b8302f',
} as const;

export type HrZoneKey = keyof typeof hrZone;

/** Ordered Z1..Z5 tuple for iterating zones in ramp order. */
export const HR_ZONES = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;

/** Alias of {@link hrZone}, named for the "zone fill colour" reading at call sites. */
export const HR_ZONE_COLORS: Record<HrZoneKey, string> = hrZone;

/**
 * What each band is called wherever one is named to the athlete — the zones
 * editor in Settings and Profile's time-in-zone legend read the same wording,
 * since they describe the same five bands.
 */
export const HR_ZONE_LABELS: Record<HrZoneKey, string> = {
    Z1: 'Z1 · Recovery',
    Z2: 'Z2 · Easy',
    Z3: 'Z3 · Aerobic',
    Z4: 'Z4 · Threshold',
    Z5: 'Z5 · Max',
};

/**
 * Periodization-phase fills for the Plan season summary (phase-progress bar,
 * week timeline). A validated-distinct (dataviz skill's `validate_palette.js`,
 * CVD ΔE >= 15 on every co-occurring pair) categorical hue per phase,
 * deliberately drawn away from `PALETTE.overloaded`/`gassed`/
 * `chill` — those three are already committed to per-run Mood colors
 * (see `lib/mood.ts`) and reusing one here for a phase would collide with
 * that existing meaning on the same page. `deload` only ever co-occurs with
 * `build` (self-scaled seasons only cycle those two — race-oriented seasons
 * only ever see `base`/`build`/`peak`/`taper`, see `App\Enums\PlanPhase`), so
 * its deliberately desaturated `stone` reads as "resting" without needing to
 * be hue-distinct from `peak`/`taper`, which it never shares a chart with.
 */
export const PHASE_COLORS = {
    base: PALETTE.leaf,
    build: PALETTE.horizonDeep,
    peak: PALETTE.ember,
    taper: PALETTE.citrus,
    deload: PALETTE.stone,
} as const;

export type PlanPhaseKey = keyof typeof PHASE_COLORS;
