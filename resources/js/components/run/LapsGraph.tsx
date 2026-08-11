import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

import type { StreamSummaryLap } from '@/types/inertia';

import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { countUpEase, fadeInUp, staggerContainer } from '@/lib/motion';
import { formatDurationHMS, formatPace } from '@/lib/pace';
import {
    barRowFill,
    computeBarWidth,
    paceScale,
    paceSecOf,
} from '@/lib/splits';

const ROW_GRID =
    'grid-cols-[48px_140px_56px_56px_56px_56px] items-center gap-2.5 lg:grid-cols-[56px_1fr_70px_70px_70px_70px] lg:gap-3';

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
        <Card as="section" padding="lg" className={cn('shadow-sm', className)}>
            <header className="mb-1.5 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Laps</SectionLabel>
                {fastest != null && fastestLap != null && (
                    <p className="font-display text-sm italic text-ink-2">
                        Fastest at lap {fastestLap},{' '}
                        <span className="font-semibold text-horizon-deep">
                            {formatPace(fastest)}/km
                        </span>
                    </p>
                )}
            </header>
            <p className="mb-3 text-label-micro text-ink-3">
                Orange bar = fastest lap, dark = the rest. Left number = each
                lap&apos;s distance.
            </p>

            {/* The -mx-3/px-3 bleed lives on this wrapper, not per row: nested inside
                a row it would bleed left of the scrollable viewport's origin and get
                clipped there, cutting off the highlight's rounded corner. */}
            <motion.div
                variants={staggerContainer}
                initial="hidden"
                animate="visible"
                className="-mx-3 flex flex-col gap-1 overflow-x-auto px-3"
            >
                {laps.map((lap, idx) => {
                    const sec = paceSecOf(lap);
                    const isFast = sec != null && sec === fastest;
                    return (
                        <motion.div
                            key={`lap-${lap.lap}`}
                            variants={fadeInUp}
                            className={cn(
                                'grid',
                                ROW_GRID,
                                'rounded-lg px-3 py-2 lg:py-2.5',
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
                                <motion.div
                                    className={cn(
                                        'h-full origin-left rounded',
                                        isFast ? 'bg-horizon' : 'bg-sky',
                                    )}
                                    style={{
                                        width: `${computeBarWidth(sec, fastest, slowest)}%`,
                                    }}
                                    initial={{ scaleX: 0 }}
                                    animate={{ scaleX: 1 }}
                                    transition={{
                                        duration: 0.6,
                                        ease: countUpEase,
                                    }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {lap.pace}
                            </div>
                            <div className="flex items-center justify-end gap-1 font-sans text-xs tabular-nums text-ink-2">
                                <Icon
                                    icon="mdi:timer-outline"
                                    width={12}
                                    height={12}
                                    aria-hidden
                                />
                                {formatDurationHMS(lap.elapsed_sec)}
                            </div>
                            <div className="text-right font-sans text-xs tabular-nums text-ink-2">
                                ♡ {lap.avg_hr ?? '—'}
                            </div>
                            <div className="flex items-center justify-end gap-1 font-sans text-xs tabular-nums text-ink-2">
                                <Icon
                                    icon="mdi:shoe-print"
                                    width={12}
                                    height={12}
                                    aria-hidden
                                />
                                {lap.avg_cadence_spm ?? '—'}
                            </div>
                        </motion.div>
                    );
                })}
            </motion.div>
        </Card>
    );
}
