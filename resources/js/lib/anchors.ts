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

export function anchorElementId(anchor: string): string | null {
    const split = /^split:([1-9]\d*)$/.exec(anchor);
    if (split) {
        return `anchor-split-${split[1]}`;
    }

    const zone = /^zone:(z[1-5])$/.exec(anchor);
    if (zone) {
        return `anchor-zone-${zone[1]}`;
    }

    const metric = /^metric:([a-z_]+)$/.exec(anchor);
    if (metric) {
        return `anchor-metric-${metric[1]}`;
    }

    return null;
}

/** What the affordance calls the thing it points at, in the reader's words. */
export function anchorLabel(anchor: string): string | null {
    const split = /^split:([1-9]\d*)$/.exec(anchor);
    if (split) {
        return `km ${split[1]}`;
    }

    const zone = /^zone:z([1-5])$/.exec(anchor);
    if (zone) {
        return `zone ${zone[1]}`;
    }

    const metric = /^metric:([a-z_]+)$/.exec(anchor);
    if (metric) {
        return metric[1].replace(/_/g, ' ');
    }

    return null;
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
