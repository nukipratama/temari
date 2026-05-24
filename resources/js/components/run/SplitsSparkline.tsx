import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';

interface SplitsSparklineProps {
    /** Pace per km, in seconds. Lower = faster. */
    paceSec: ReadonlyArray<number>;
    /** Optional className for the wrapper card. */
    className?: string;
}

export default function SplitsSparkline({ paceSec, className }: Readonly<SplitsSparklineProps>) {
    if (paceSec.length === 0) {
        return (
            <div
                className={cn(
                    'rounded-xl border border-cream/[0.12] bg-sky/40 px-5 py-4 font-display text-xs italic text-cream/55',
                    className,
                )}
            >
                Splits belum tersedia untuk rekor ini.
            </div>
        );
    }

    const fastest = Math.min(...paceSec);
    const slowest = Math.max(...paceSec);
    const first = paceSec[0];
    const last = paceSec[paceSec.length - 1];
    const negativeSplit = last < first;
    const fastestIdx = paceSec.indexOf(fastest);

    return (
        <div className={cn('rounded-xl border border-cream/[0.12] bg-sky/40 px-5 py-4 backdrop-blur', className)}>
            <header className="mb-3 flex items-baseline justify-between gap-3">
                <div className="font-mono text-[10px] uppercase tracking-[0.14em] text-cream/60">
                    Splits · pace per km
                </div>
                <div className="font-display text-[13px] italic text-horizon">
                    {negativeSplit ? 'negatif-split rapi' : 'splits stabil'}: {formatPace(first)} → {formatPace(last)}
                </div>
            </header>
            <div className="flex h-[72px] items-end gap-1.5">
                {paceSec.map((p, i) => {
                    const range = slowest - fastest;
                    const norm = range > 0 ? (slowest - p) / range : 1;
                    const heightPct = norm * 70 + 30;
                    const isBest = i === fastestIdx;
                    return (
                        <div key={i} className="flex flex-1 flex-col items-center gap-1.5">
                            <div
                                className={cn(
                                    'w-full min-h-[8px] rounded-sm transition-opacity',
                                    isBest ? 'bg-horizon' : 'bg-cream/35',
                                )}
                                style={{ height: `${heightPct}%` }}
                                aria-label={`Km ${i + 1}: ${formatPace(p)}`}
                                title={`Km ${i + 1} · ${formatPace(p)}/km`}
                            />
                            <div className="font-mono text-[9px] tabular-nums text-cream/50">{i + 1}</div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
