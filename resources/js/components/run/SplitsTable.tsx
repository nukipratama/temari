import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

import type { StreamSummaryPartial, StreamSummaryPerKm } from '@/types/inertia';

import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { countUpEase, fadeInUp, staggerContainer } from '@/lib/motion';
import { formatKm, formatPace } from '@/lib/pace';
import {
    barRowFill,
    computeBarWidth,
    paceScale,
    paceSecOf,
} from '@/lib/splits';

const ROW_GRID =
    'grid-cols-[34px_140px_56px_56px_56px] items-center gap-2.5 lg:grid-cols-[40px_1fr_70px_70px_70px] lg:gap-3';

export default function SplitsTable({
    rows,
    partial,
    className,
}: Readonly<{
    rows: StreamSummaryPerKm[];
    partial?: StreamSummaryPartial | null;
    className?: string;
}>) {
    const { fastest, slowest } = paceScale(rows);
    const fastestKm =
        fastest != null
            ? (rows.find((r) => paceSecOf(r) === fastest)?.km ?? null)
            : null;

    // Stable, collision-proof keys baked here — km for full splits, positional for
    // the trailing partial — so the render map keys off a data field, not its index.
    const keyedRows = rows.map((row, i) => ({
        row,
        key: row.km != null ? `km-${row.km}` : `partial-${i}`,
    }));

    return (
        <Card as="section" padding="hero" className={className}>
            <header className="mb-1.5 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Splits per km</SectionLabel>
                {fastest != null && fastestKm != null && (
                    <p className="font-display text-sm italic text-ink-2">
                        Fastest at km {fastestKm},{' '}
                        <span className="font-semibold text-horizon-deep">
                            {formatPace(fastest)}/km
                        </span>
                    </p>
                )}
            </header>
            {/* One dense chart at every width; on phones the row is wider than the
                viewport so HR + cadence stay reachable by horizontal scroll instead
                of folding away. The binary bar color needs a one-line key once the
                card affordance is gone. */}
            <p className="mb-3 text-label-micro text-ink-3">
                Orange bar = fastest km, dark = the rest
                {partial ? ', dashed = remainder' : ''}.
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
                {keyedRows.map(({ row, key }, idx) => {
                    const sec = paceSecOf(row);
                    const isFast = sec != null && sec === fastest;
                    const pctWidth = computeBarWidth(sec, fastest, slowest);
                    const rowFill = barRowFill(isFast, idx);
                    return (
                        <motion.div
                            key={key}
                            variants={fadeInUp}
                            className={cn(
                                'grid',
                                ROW_GRID,
                                // Every row gets the same rounded background box — only the
                                // bar color should differ (see computeBarWidth caller).
                                'rounded-lg px-3 py-2 lg:py-2.5',
                                rowFill,
                            )}
                        >
                            <Eyebrow token="micro" tone="ink-2">
                                KM {row.km ?? '?'}
                            </Eyebrow>
                            <div className="h-2.5 overflow-hidden rounded bg-sky/[0.06] lg:h-3">
                                <motion.div
                                    className={cn(
                                        'h-full origin-left rounded',
                                        isFast ? 'bg-horizon' : 'bg-sky',
                                    )}
                                    style={{ width: `${pctWidth}%` }}
                                    initial={{ scaleX: 0 }}
                                    animate={{ scaleX: 1 }}
                                    transition={{
                                        duration: 0.6,
                                        ease: countUpEase,
                                    }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {row.pace ?? '—'}
                            </div>
                            <div className="text-right font-sans text-xs tabular-nums text-ink-2">
                                ♡ {row.avg_hr ?? '—'}
                            </div>
                            <div className="flex items-center justify-end gap-1 font-sans text-xs tabular-nums text-ink-2">
                                <Icon
                                    icon="mdi:shoe-print"
                                    width={12}
                                    height={12}
                                    aria-hidden
                                />
                                {row.avg_cadence_spm ?? '—'}
                            </div>
                        </motion.div>
                    );
                })}
                {partial && <SplitPartialRow partial={partial} />}
            </motion.div>
        </Card>
    );
}

// The trailing "sisa" segment (e.g. the last 0.7 km of a 5.7 km run). Rendered
// muted with a dashed, empty (non-comparative) bar and detached by a hairline so
// a glance reads "outside the scale, not ranked against the full kms" — its
// normalized pace must never be compared head-to-head with a full km.
function SplitPartialRow({
    partial,
}: Readonly<{ partial: StreamSummaryPartial }>) {
    return (
        <div
            className={cn(
                'grid',
                ROW_GRID,
                'mt-1 rounded-lg border-t border-cream-deep px-3 py-2 lg:py-2.5',
            )}
        >
            <div className="font-mono text-[11px] uppercase tracking-[0.02em] text-ink-3">
                {formatKm(partial.distance_m, 1)} KM
            </div>
            <div className="h-2.5 rounded border border-dashed border-sky/20 bg-sky/[0.06] lg:h-3" />
            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink-3">
                {partial.pace ?? '—'}
            </div>
            <div className="text-right font-sans text-xs tabular-nums text-ink-3">
                ♡ {partial.avg_hr ?? '—'}
            </div>
            <div className="flex items-center justify-end gap-1 font-sans text-xs tabular-nums text-ink-3">
                <Icon
                    icon="mdi:shoe-print"
                    width={12}
                    height={12}
                    aria-hidden
                />
                {partial.avg_cadence_spm ?? '—'}
            </div>
        </div>
    );
}
