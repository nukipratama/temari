import { ANCHOR_KIND_VALUES, type AnchorKind } from '@/types/generated';

/**
 * The anchor namespace `RunInsightNarrator` already validates server-side
 * (`split:<n>`, `zone:z1..z5`, `metric:<name>`), resolved here to the element
 * that actually draws the thing on this page.
 *
 * An anchor being well-formed does not mean it has a rendering site: the
 * narrator validates against the run's `StreamSummary`, which knows readings
 * no component draws. So callers ask the DOM, not this map, before offering an
 * affordance for one.
 */
const HIGHLIGHT_MS = 1600;

/**
 * The value half of each kind's grammar, mirroring
 * `App\Services\AI\Anchor\AnchorKind::valuePattern()`. Typed as a total
 * record over the generated `AnchorKind`, so adding a kind in PHP fails the
 * TypeScript build here until this side handles it — the two used to hold
 * separate regexes for one grammar with nothing keeping them honest.
 */
const VALUE_PATTERN: Record<AnchorKind, string> = {
    split: '[1-9]\\d*',
    zone: 'z[1-5]',
    metric: '[a-z_]+',
};

interface ParsedAnchor {
    kind: AnchorKind;
    value: string;
}

/** The one place the anchor grammar is parsed; id and label both read from it. */
function parseAnchor(anchor: string): ParsedAnchor | null {
    for (const kind of ANCHOR_KIND_VALUES) {
        const match = new RegExp(`^${kind}:(${VALUE_PATTERN[kind]})$`).exec(
            anchor,
        );
        if (match) {
            return { kind, value: match[1] };
        }
    }

    return null;
}

export function anchorElementId(anchor: string): string | null {
    const parsed = parseAnchor(anchor);
    if (parsed === null) {
        return null;
    }

    return `anchor-${parsed.kind}-${parsed.value}`;
}

/** What the affordance calls the thing it points at, in the reader's words. */
export function anchorLabel(anchor: string): string | null {
    const parsed = parseAnchor(anchor);
    if (parsed === null) {
        return null;
    }

    switch (parsed.kind) {
        case 'split':
            return `km ${parsed.value}`;
        case 'zone':
            return `zone ${parsed.value.slice(1)}`;
        case 'metric':
            return parsed.value.replace(/_/g, ' ');
    }
}

/**
 * Whether `VitalsCard` draws the grade tile. Shared with that component rather
 * than restated, so the set of citable anchors cannot drift from the set of
 * rendered ones. Only a run that actually climbed shows a grade.
 */
export function showsGrade(summary: StreamSummaryish): boolean {
    const grade = Number(summary.max_grade_pct);
    return (
        summary.max_grade_pct != null && Number.isFinite(grade) && grade >= 3
    );
}

/** Whether `VitalsCard` draws the decoupling readout. */
export function showsDecoupling(summary: StreamSummaryish): boolean {
    return (
        summary.decoupling_pct != null &&
        Number.isFinite(Number(summary.decoupling_pct))
    );
}

interface StreamSummaryish {
    max_grade_pct?: unknown;
    decoupling_pct?: unknown;
    gap_pace?: unknown;
}

/**
 * Which anchors this run page actually draws.
 *
 * A claim's anchor is validated server-side against the run's `StreamSummary`,
 * which holds readings no component renders — `hr_drift`, `cadence_drop`,
 * `pace_variability` and `negative_split` have no site on this page. Those
 * claims get no control at all rather than one that goes nowhere.
 */
export function drawnRunAnchors(
    summary: StreamSummaryish,
    splitCount: number,
    zones: Readonly<Record<string, number | undefined>> | null,
): ReadonlySet<string> {
    const drawn = new Set<string>();

    for (let km = 1; km <= splitCount; km++) {
        drawn.add(`split:${km}`);
    }

    for (const [zone, pct] of Object.entries(zones ?? {})) {
        if ((pct ?? 0) > 0) {
            drawn.add(`zone:${zone.toLowerCase()}`);
        }
    }

    if (showsGrade(summary)) {
        drawn.add('metric:grade');
        // The flat-pace tile is nested inside the grade tile's own condition.
        if (summary.gap_pace != null) {
            drawn.add('metric:gap_pace');
        }
    }

    if (showsDecoupling(summary)) {
        drawn.add('metric:decoupling');
    }

    return drawn;
}

/** Scroll the anchored element into view and mark it briefly. */
export function revealAnchor(anchor: string): void {
    const id = anchorElementId(anchor);
    const element = id === null ? null : document.getElementById(id);
    if (element === null) {
        return;
    }

    element.scrollIntoView({ block: 'center', behavior: 'smooth' });
    element.dataset.anchorHit = 'true';
    window.setTimeout(() => {
        delete element.dataset.anchorHit;
    }, HIGHLIGHT_MS);
}
