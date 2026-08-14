/**
 * Reads the design system back out of the live stylesheet.
 *
 * Everything here takes the CSS as it actually shipped — the token names come
 * from the `:root` rules the browser parsed, the values from
 * `getComputedStyle` — so /devtools/design cannot drift from
 * [resources/css/app.css](../../css/app.css) the way a hand-copied token list
 * in TypeScript would. The audit rules mirror
 * [resources/brand/build-tokens.mjs](../../brand/build-tokens.mjs), which is
 * what generates the `@theme` block in the first place.
 */

import GROUND_KINDS from '../../brand/grounds.json';

const ROOT_SELECTOR = /(^|,)\s*:root\b/;
const GROUND_SELECTOR = /body\[data-time-of-day=['"]?([a-z0-9-]+)['"]?\]/i;

interface StyleSheetLike {
    readonly cssRules?: ArrayLike<unknown> | null;
}

/** A paper the app can render: the base surface, or one dawn-shift overrides it to. */
export interface Ground {
    name: string;
    value: string;
}

function collectFromRule(rule: unknown, into: Set<string>): void {
    const { style, selectorText } = rule as {
        style?: CSSStyleDeclaration;
        selectorText?: string;
    };

    if (style && selectorText && ROOT_SELECTOR.test(selectorText)) {
        for (let i = 0; i < style.length; i += 1) {
            const property = style.item(i);
            if (property.startsWith('--')) {
                into.add(property);
            }
        }
    }

    const nested = (rule as { cssRules?: ArrayLike<unknown> | null }).cssRules;
    if (nested) {
        for (const child of Array.from(nested)) {
            collectFromRule(child, into);
        }
    }
}

/**
 * Every custom property declared on a `:root` rule, including ones nested in
 * `@layer` / `@media` blocks (Tailwind emits `@layer theme { :root, :host }`).
 * Cross-origin sheets throw on `cssRules` and are skipped.
 */
export function collectTokenNames(
    sheets: Iterable<StyleSheetLike> | ArrayLike<StyleSheetLike>,
): string[] {
    const names = new Set<string>();

    for (const sheet of Array.from(sheets as ArrayLike<StyleSheetLike>)) {
        let rules: ArrayLike<unknown> | null | undefined;
        try {
            rules = sheet.cssRules;
        } catch {
            continue;
        }
        if (!rules) {
            continue;
        }
        for (const rule of Array.from(rules)) {
            collectFromRule(rule, names);
        }
    }

    return [...names].sort();
}

function collectGroundsFromRule(
    rule: unknown,
    into: Map<string, string>,
): void {
    const { style, selectorText } = rule as {
        style?: CSSStyleDeclaration;
        selectorText?: string;
    };

    const match = selectorText ? GROUND_SELECTOR.exec(selectorText) : null;
    if (style && match) {
        const surface = style.getPropertyValue('--color-surface').trim();
        if (surface !== '') {
            into.set(match[1], surface);
        }
    }

    const nested = (rule as { cssRules?: ArrayLike<unknown> | null }).cssRules;
    if (nested) {
        for (const child of Array.from(nested)) {
            collectGroundsFromRule(child, into);
        }
    }
}

/**
 * Every paper an `-ink` token can land on, read back out of the live values:
 * one per `body[data-time-of-day]` rule dawn-shift declares, plus every
 * background
 * [grounds.json](../../brand/grounds.json) classifies as paper. Scraped and
 * resolved rather than listed, so a sixth dawn-shift bucket, or a new page
 * ground the components start painting, is audited as soon as it is classified.
 *
 * A classified ground the stylesheet no longer resolves is kept with an empty
 * value on purpose: it scores `null` and reports as a failure, rather than
 * quietly shrinking the set the way the missing `--color-cream-deep` did.
 */
export function collectPaperGrounds(
    sheets: Iterable<StyleSheetLike> | ArrayLike<StyleSheetLike>,
    values: Record<string, string>,
): Ground[] {
    const found = new Map<string, string>();

    for (const sheet of Array.from(sheets as ArrayLike<StyleSheetLike>)) {
        let rules: ArrayLike<unknown> | null | undefined;
        try {
            rules = sheet.cssRules;
        } catch {
            continue;
        }
        if (!rules) {
            continue;
        }
        for (const rule of Array.from(rules)) {
            collectGroundsFromRule(rule, found);
        }
    }

    return [
        ...GROUND_KINDS.paper.map((name) => ({
            name,
            value: values[`--color-${name}`] ?? '',
        })),
        ...[...found.entries()].map(([name, value]) => ({
            name: `surface · ${name}`,
            value,
        })),
    ];
}

/**
 * The grounds one `-ink` token has to clear: the papers, plus its own family's
 * tinted cell when it paints one. The pairing is the naming convention, so a
 * new `-bg` cell is scored as soon as grounds.json calls it scoped.
 */
function groundsForInk(
    inkToken: string,
    values: Record<string, string>,
    papers: ReadonlyArray<Ground>,
): Ground[] {
    const family = inkToken.slice('--color-'.length, -'-ink'.length);
    const own = `${family}-bg`;

    return GROUND_KINDS.scoped.includes(own) && values[`--color-${own}`]
        ? [...papers, { name: own, value: values[`--color-${own}`] }]
        : [...papers];
}

/** Resolve each token against an element, so cascaded overrides are included. */
export function readTokenValues(
    names: ReadonlyArray<string>,
    element: Element,
): Record<string, string> {
    const computed = getComputedStyle(element);
    const values: Record<string, string> = {};
    for (const name of names) {
        const value = computed.getPropertyValue(name).trim();
        if (value !== '') {
            values[name] = value;
        }
    }
    return values;
}

/** Token names under one `--prefix-`, in declaration-independent sorted order. */
export function tokensWithPrefix(
    names: ReadonlyArray<string>,
    prefix: string,
): string[] {
    return names.filter((name) => name.startsWith(prefix));
}

/**
 * Colour tokens bucketed by family — the segment after `--color-`, minus the
 * `-ink` / `-bg` / `-deep` suffixes that are members of a family, not families.
 */
export function groupColorFamilies(
    names: ReadonlyArray<string>,
): Array<[string, string[]]> {
    const families = new Map<string, string[]>();

    for (const name of tokensWithPrefix(names, '--color-')) {
        const rest = name.slice('--color-'.length);
        const family = rest.split('-')[0];
        families.set(family, [...(families.get(family) ?? []), name]);
    }

    return [...families.entries()];
}

function channels(hex: string): [number, number, number] | null {
    const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(hex);
    if (short) {
        return [
            parseInt(short[1] + short[1], 16),
            parseInt(short[2] + short[2], 16),
            parseInt(short[3] + short[3], 16),
        ];
    }

    const long = /^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i.exec(hex);
    if (long) {
        const n = parseInt(long[1], 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    const rgb = /^rgba?\(([^)]+)\)$/i.exec(hex);
    if (rgb) {
        const parts = rgb[1]
            .split(/[\s,/]+/)
            .filter(Boolean)
            .map(Number);
        if (parts.length >= 3 && parts.slice(0, 3).every(Number.isFinite)) {
            return [parts[0], parts[1], parts[2]];
        }
    }

    return null;
}

/** WCAG relative luminance. */
export function luminance(color: string): number | null {
    const rgb = channels(color);
    if (!rgb) {
        return null;
    }
    const [r, g, b] = rgb.map((v) => {
        const c = v / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio, or null when either colour is not parseable. */
export function contrastRatio(a: string, b: string): number | null {
    const [x, y] = [luminance(a), luminance(b)];
    if (x === null || y === null) {
        return null;
    }
    const [light, dark] = x > y ? [x, y] : [y, x];
    return (light + 0.05) / (dark + 0.05);
}

export interface ContrastRow {
    use: string;
    fg: string;
    bg: string;
    min: number;
    ratio: number | null;
    pass: boolean;
    /** The fill is too light to carry contrast itself; its -ink outline does. */
    outlined?: boolean;
}

/**
 * The pairs that have to hold for the system to be usable: text on paper, text
 * on a dark panel, text on a CTA fill, and the two non-text minimums (3:1 for a
 * meaningful graphic, 1.4:1 for a separator).
 */
const PAIRS: ReadonlyArray<[string, string, string, number]> = [
    ['ink', 'paper', 'Body text', 4.5],
    ['ink-2', 'paper', 'Secondary text', 4.5],
    ['ink-3', 'paper', 'Meta text', 4.5],
    ['horizon-ink', 'paper', 'Gold as text', 4.5],
    ['cream', 'sky', 'Text on indigo', 4.5],
    ['ink-on-sky', 'sky', 'Muted on indigo', 4.5],
    ['ink', 'horizon', 'Text on gold CTA', 4.5],
    ['cream', 'leaf-deep', 'Text on leaf CTA', 4.5],
    ['cream', 'ember-deep', 'Text on ember CTA', 4.5],
    ['cream', 'sky-2', 'Text on sky-2', 4.5],
    ['horizon', 'sky', 'Gold mark on indigo', 3.0],
    ['line', 'paper', 'Separator', 1.4],
];

/** Stands for the whole paper set rather than one token. */
const PAPER = 'paper';

/**
 * Scores one pair. A pair grounded on `paper` is scored against every ground
 * the app can paint under text and reported at its worst, because that is what
 * the token has to survive — scoring only `--color-surface` and its dawn-shift
 * drifts is what let every hue-derived `-ink` ship at 4.3:1 on the page ground.
 */
function row(
    values: Record<string, string>,
    grounds: ReadonlyArray<Ground>,
    fg: string,
    bg: string,
    use: string,
    min: number,
    outlined = false,
): ContrastRow {
    const against =
        bg === PAPER
            ? grounds
            : [{ name: '', value: values[bg] ?? '' } satisfies Ground];

    const worst = against
        .map((ground) => ({
            ground,
            ratio: contrastRatio(values[fg] ?? '', ground.value),
        }))
        .reduce((a, b) => {
            if (a.ratio === null) {
                return a;
            }
            return b.ratio === null || b.ratio < a.ratio ? b : a;
        });

    return {
        use,
        fg,
        bg: worst.ground.name === '' ? bg : `${bg} · ${worst.ground.name}`,
        min,
        ratio: worst.ratio,
        pass: worst.ratio !== null && worst.ratio >= min,
        ...(outlined ? { outlined } : {}),
    };
}

/**
 * Audits the live values. Beyond the fixed pairs, every `-ink` token is checked
 * against its own fill: the `-ink` member must clear 4.5:1 as text, and the fill
 * must clear 3:1 as a graphic — or, when it is too light to (a legendary gold, an
 * uncommon green), its `-ink` outline is what gets tested instead, which is the
 * rule that lets those two keep their vibrancy.
 */
export function auditContrast(
    values: Record<string, string>,
    grounds: ReadonlyArray<Ground>,
): ContrastRow[] {
    const rows = PAIRS.filter(
        ([fg, bg]) =>
            values[`--color-${fg}`] !== undefined &&
            (bg === PAPER || values[`--color-${bg}`] !== undefined),
    ).map(([fg, bg, use, min]) =>
        row(
            values,
            grounds,
            `--color-${fg}`,
            bg === PAPER ? PAPER : `--color-${bg}`,
            use,
            min,
        ),
    );

    for (const inkToken of Object.keys(values).sort()) {
        const fillToken = inkToken.replace(/-ink$/, '');
        if (
            !inkToken.startsWith('--color-') ||
            !inkToken.endsWith('-ink') ||
            fillToken === inkToken ||
            values[fillToken] === undefined
        ) {
            continue;
        }

        const label = fillToken.slice('--color-'.length);
        rows.push(
            row(
                values,
                groundsForInk(inkToken, values, grounds),
                inkToken,
                PAPER,
                `${label} label`,
                4.5,
            ),
        );

        const fill = row(values, grounds, fillToken, PAPER, `${label} fill`, 3);
        rows.push(
            fill.pass
                ? fill
                : row(
                      values,
                      grounds,
                      inkToken,
                      PAPER,
                      `${label} fill outline`,
                      3,
                      true,
                  ),
        );
    }

    return rows;
}

export interface SurfaceRow {
    name: string;
    radius: string;
    shadow: string;
    radiusOnScale: boolean;
    shadowOnScale: boolean;
}

function normalize(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

/**
 * A shadow utility composes through Tailwind's ring/inset variables, so a
 * `shadow-e1` element computes to the token's two layers preceded by four fully
 * transparent placeholders. Dropping those is what lets a rendered surface be
 * compared against the token it was supposed to use.
 */
export function normalizeShadow(value: string): string {
    return normalize(value)
        .split(/,(?![^(]*\))/)
        .map(normalize)
        .filter((layer) => !/^rgba\(0, ?0, ?0, ?0\)( 0px)+$/.test(layer))
        .join(', ');
}

/**
 * Checks a rendered surface against the scales. A card that picked its corner
 * from a Tailwind default instead of `--radius-*` shows up here as a failure
 * rather than as an inconsistency nobody notices. Both sides are computed
 * values — the reference comes from probes painted with the tokens themselves,
 * because the browser rewrites a shadow declaration on its way to `computed`.
 */
export function auditSurface(
    name: string,
    computed: { borderRadius: string; boxShadow: string },
    reference: { radii: ReadonlyArray<string>; shadows: ReadonlyArray<string> },
): SurfaceRow {
    const radius = normalize(computed.borderRadius);
    const shadow = normalizeShadow(computed.boxShadow);

    return {
        name,
        radius,
        shadow,
        radiusOnScale: reference.radii.map(normalize).includes(radius),
        shadowOnScale:
            shadow === 'none' ||
            shadow === '' ||
            reference.shadows.map(normalizeShadow).includes(shadow),
    };
}
