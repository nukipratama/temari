import { parsePaceSec } from '@/lib/pace';
import type { StreamSummaryPerKm } from '@/types/inertia';

export function paceSecOf(row: StreamSummaryPerKm): number | null {
    const sec = parsePaceSec(row.pace);
    return Number.isFinite(sec) ? sec : null;
}

// Per-km spread (seconds) at which the bar-width band reaches its full 50-point swing.
export const FULL_SPREAD_SEC = 30;

export function computeBarWidth(sec: number | null, fastest: number | null, slowest: number | null): number {
    if (sec == null || fastest == null || slowest == null || slowest === fastest) return 60;
    // Faster pace = wider bar, anchored at 90% for the fastest km. The band amplitude
    // scales with the ABSOLUTE split spread, so a run where every km is within a second
    // or two renders as near-equal full-width bars ("konsisten") instead of a misleading
    // 40→90 swing that contradicts the "pacing sangat konsisten" narration above it.
    const spread = slowest - fastest;
    const amplitude = Math.min(spread / FULL_SPREAD_SEC, 1) * 50;
    const t = (slowest - sec) / spread; // 0 (slowest) .. 1 (fastest)
    return Math.round(90 - (1 - t) * amplitude);
}
