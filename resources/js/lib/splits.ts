import { parsePaceSec } from '@/lib/pace';

/** Any row a pace bar is drawn for — a per-km split or a watch lap. */
interface PaceRow {
    pace: string;
}

export function paceSecOf(row: PaceRow): number | null {
    const sec = parsePaceSec(row.pace);
    return Number.isFinite(sec) ? sec : null;
}

/** The two anchors `computeBarWidth` scales a row set against. Both null when
 *  no row carries a parseable pace, which collapses every bar to neutral. */
export function paceScale(rows: readonly PaceRow[]): {
    fastest: number | null;
    slowest: number | null;
} {
    const paces = rows
        .map((row) => paceSecOf(row))
        .filter((sec): sec is number => sec != null);
    if (paces.length === 0) return { fastest: null, slowest: null };
    return { fastest: Math.min(...paces), slowest: Math.max(...paces) };
}

// Per-km spread (seconds) at which the bar-width band reaches its full 50-point swing.
export const FULL_SPREAD_SEC = 30;

export function computeBarWidth(
    sec: number | null,
    fastest: number | null,
    slowest: number | null,
): number {
    if (
        sec == null ||
        fastest == null ||
        slowest == null ||
        slowest === fastest
    )
        return 60;
    // Faster pace = wider bar, anchored at 90% for the fastest km. The band amplitude
    // scales with the ABSOLUTE split spread, so a run where every km is within a second
    // or two renders as near-equal full-width bars ("consistent") instead of a misleading
    // 40→90 swing that contradicts the "pacing was very consistent" narration above it.
    const spread = slowest - fastest;
    const amplitude = Math.min(spread / FULL_SPREAD_SEC, 1) * 50;
    const t = (slowest - sec) / spread; // 0 (slowest) .. 1 (fastest)
    return Math.round(90 - (1 - t) * amplitude);
}

// Every bar row (splits and laps alike) shares the same rounded box; only this
// fill differs — horizon tint for the fastest row, a faint zebra stripe otherwise.
export function barRowFill(isFast: boolean, idx: number): string {
    if (isFast) return 'bg-horizon/[0.08]';
    if (idx % 2 === 1) return 'bg-cream-deep/30';
    return 'bg-sky/[0.03]';
}
