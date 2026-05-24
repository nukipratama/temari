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

    return (
        <div className={cn('rounded-xl border border-cream/[0.12] bg-sky/40 px-5 py-4 backdrop-blur', className)}>
            <header className="mb-2.5 flex items-baseline justify-between">
                <div className="font-mono text-[9px] uppercase tracking-[0.14em] text-cream/60">
                    Splits · pace per km
                </div>
                <div className="font-display text-[13px] italic text-horizon">
                    {negativeSplit ? 'negatif-split rapi' : 'splits stabil'}: {formatPace(first)} → {formatPace(last)}
                </div>
            </header>
            <div className="flex h-[50px] items-end gap-1">
                {paceSec.map((p, i) => {
                    const range = slowest - fastest || 1;
                    const heightPct = ((slowest - p) / range) * 90 + 10;
                    const isBest = p === fastest;
                    return (
                        <div key={i} className="flex flex-1 flex-col items-center gap-1">
                            <div
                                className={cn(
                                    'w-full min-h-[6px] rounded-sm',
                                    isBest ? 'bg-horizon' : 'bg-cream/40',
                                )}
                                style={{ height: `${heightPct}%` }}
                                aria-label={`Km ${i + 1}: ${formatPace(p)}`}
                            />
                            <div className="font-mono text-[9px] text-cream/45">{i + 1}</div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
