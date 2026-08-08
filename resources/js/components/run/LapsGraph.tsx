import type { StreamSummaryLap } from '@/types/inertia';

import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';
import {
    barRowFill,
    computeBarWidth,
    paceScale,
    paceSecOf,
} from '@/lib/splits';

export default function LapsGraph({
    laps,
    className,
}: Readonly<{
    laps: StreamSummaryLap[];
    className?: string;
}>) {
    const { fastest, slowest } = paceScale(laps);
    const fastestLap =
        fastest != null
            ? (laps.find((lap) => paceSecOf(lap) === fastest)?.lap ?? null)
            : null;

    return (
        <Card as="section" padding="lg" className={className}>
            <header className="mb-1.5 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Laps</SectionLabel>
                {fastest != null && fastestLap != null && (
                    <p className="font-display text-sm italic text-ink-2">
                        Paling kenceng di lap {fastestLap},{' '}
                        <span className="font-semibold text-horizon-deep">
                            {formatPace(fastest)}/km
                        </span>
                    </p>
                )}
            </header>
            <p className="mb-3 text-label-micro text-ink-3">
                Batang oranye = lap tercepat, gelap = lainnya. Angka kiri =
                panjang tiap lap.
            </p>

            <div className="flex flex-col gap-1">
                {laps.map((lap, idx) => {
                    const sec = paceSecOf(lap);
                    const isFast = sec != null && sec === fastest;
                    return (
                        <div
                            key={`lap-${lap.lap}`}
                            className={cn(
                                'grid grid-cols-[48px_1fr_56px] items-center gap-2.5 lg:grid-cols-[56px_1fr_70px_70px_70px] lg:gap-3',
                                '-mx-3 rounded-lg px-3 py-2 lg:py-2.5',
                                barRowFill(isFast, idx),
                            )}
                        >
                            <div className="font-mono text-[11px] tabular-nums tracking-[0.02em] text-ink-2">
                                {lap.distance_m}m
                            </div>
                            <div
                                role="img"
                                aria-label={`Lap ${lap.lap}, ${lap.distance_m} m, ${lap.pace} per km`}
                                className="h-2.5 overflow-hidden rounded bg-sky/[0.06] lg:h-3"
                            >
                                <div
                                    className={cn(
                                        'h-full rounded',
                                        isFast ? 'bg-horizon' : 'bg-sky',
                                    )}
                                    style={{
                                        width: `${computeBarWidth(sec, fastest, slowest)}%`,
                                    }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {lap.pace}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ♡ {lap.avg_hr ?? '—'}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ↻ {lap.avg_cadence_spm ?? '—'}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}
