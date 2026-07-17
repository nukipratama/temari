import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';

interface SplitsSparklineProps {
    /** Pace per km, in seconds (full km only). Lower = faster. */
    paceSec: ReadonlyArray<number>;
    /** Normalized pace of the trailing "sisa" segment, or null. Rendered as a
     *  de-emphasized, non-crownable ghost bar — kept out of the scored bars so it
     *  never enters the verdict, crown, or scale. */
    partialPaceSec?: number | null;
    /** Optional className for the wrapper card. */
    className?: string;
}

export default function SplitsSparkline({ paceSec, partialPaceSec, className }: Readonly<SplitsSparklineProps>) {
    if (paceSec.length === 0) {
        return (
            <div
                className={cn(
                    'rounded-xl border border-cream/[0.12] bg-sky/40 px-5 py-4 font-display text-xs italic text-ink-on-sky',
                    className,
                )}
            >
                Splits belum tersedia untuk rekor ini.
            </div>
        );
    }

    // Per-km bars stay readable up to ~16 splits. Longer runs (HM, marathon)
    // average consecutive km into ~16 buckets so the pace shape is legible
    // instead of 40+ hair-thin bars that all read the same.
    const MAX_BARS = 16;
    const perKm = paceSec.length <= MAX_BARS;
    const bucketSize = perKm ? 1 : Math.ceil(paceSec.length / MAX_BARS);
    const bars = perKm
        ? paceSec.map((p, i) => ({ pace: p, from: i + 1, to: i + 1 }))
        : Array.from({ length: Math.ceil(paceSec.length / bucketSize) }, (_, b) => {
              const start = b * bucketSize;
              const chunk = paceSec.slice(start, start + bucketSize);
              const avg = chunk.reduce((sum, p) => sum + p, 0) / chunk.length;
              return { pace: avg, from: start + 1, to: Math.min(start + bucketSize, paceSec.length) };
          });

    const barPaces = bars.map((b) => b.pace);
    const fastest = Math.min(...barPaces);
    const slowest = Math.max(...barPaces);
    const range = slowest - fastest;
    const first = paceSec[0];
    const last = paceSec[paceSec.length - 1];
    const negativeSplit = last < first;
    const fastestIdx = barPaces.indexOf(fastest);
    const kmLabel = (b: { from: number; to: number }) => (b.from === b.to ? `${b.to}` : `${b.from}–${b.to}`);
    // Thin the km-scale labels to ~6 evenly-spaced ticks so they stay legible at
    // 320px instead of colliding into an unreadable run of digits.
    const labelStep = Math.max(1, Math.ceil(bars.length / 6));

    return (
        <div className={cn('rounded-xl border border-cream/[0.12] bg-sky/40 px-5 py-4 backdrop-blur', className)}>
            <header className="mb-3 flex items-baseline justify-between gap-3">
                <div className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-on-sky">
                    Splits · pace per km{perKm ? '' : ` · rata-rata tiap ${bucketSize} km`}
                </div>
                <div className="font-display text-[13px] italic text-horizon">
                    {negativeSplit ? 'negatif-split rapi' : 'splits stabil'}: {formatPace(first)} → {formatPace(last)}
                </div>
            </header>
            <div className="flex h-[72px] items-end gap-1.5">
                {bars.map((b, i) => {
                    // Widen the floor->ceiling spread (22%..100%) so small pace
                    // differences are still visible rather than a flat wall.
                    const norm = range > 0 ? (slowest - b.pace) / range : 1;
                    const heightPct = norm * 78 + 22;
                    const isBest = i === fastestIdx;
                    return (
                        <div key={`${b.from}-${b.to}`} className="flex min-w-0 flex-1 flex-col items-center gap-1.5">
                            <div
                                className={cn(
                                    'w-full min-h-[8px] rounded-sm transition-opacity',
                                    isBest ? 'bg-horizon' : 'bg-cream/35',
                                )}
                                style={{ height: `${heightPct}%` }}
                                aria-label={`Km ${kmLabel(b)}: ${formatPace(b.pace)}`}
                                title={`Km ${kmLabel(b)} · ${formatPace(b.pace)}/km`}
                            />
                            <div
                                className={cn(
                                    'font-mono text-[11px] tabular-nums text-ink-on-sky',
                                    // Hidden ticks keep their box (so the row stays aligned) but
                                    // don't paint, leaving only the thinned, legible labels.
                                    i % labelStep !== 0 && 'invisible',
                                )}
                            >
                                {b.to}
                            </div>
                        </div>
                    );
                })}
                {partialPaceSec != null && (
                    // Rendered OUTSIDE the bars array on purpose: it must never enter
                    // fastest/slowest/fastestIdx or it re-poisons the verdict + crown.
                    // Fixed height (out of scale), dashed cream ghost, visible "sisa" key
                    // since the sparkline has no legend.
                    <div className="ml-1 flex min-w-0 flex-1 flex-col items-center gap-1.5">
                        <div
                            className="min-h-[8px] w-full rounded-sm border border-dashed border-cream/30 bg-cream/12"
                            style={{ height: '38%' }}
                            aria-label={`Sisa: ${formatPace(partialPaceSec)}/km`}
                            title={`Sisa · ${formatPace(partialPaceSec)}/km`}
                        />
                        <div className="font-mono text-[11px] italic text-ink-on-sky">sisa</div>
                    </div>
                )}
            </div>
        </div>
    );
}
