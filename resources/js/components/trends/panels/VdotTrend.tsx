import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { PALETTE } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';

import type { TrendRange } from '../RangeToggle';

const Line = lazy(() => import('@/components/collection/LineChart'));

export interface VdotHistoryPoint {
    date: string;
    vdot: number | null;
}

interface VdotTrendProps {
    trend: ReadonlyArray<VdotHistoryPoint>;
    sourceCategory: string | null;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

const GRID_LINE = `${PALETTE.ink3}1f`; // 0.12 alpha

/**
 * VDOT History panel — Temari keeps the minimum VDOT across every eligible PR
 * category, so a prescribed pace never outruns a real result
 * ({@see VdotEstimator}). The history is grow-forward only
 * (TrendDailySnapshot has no backfill), so recent users will see a short
 * line that lengthens day by day rather than a full year at once.
 */
export default function VdotTrend({
    trend,
    sourceCategory,
    range,
    className,
}: Readonly<VdotTrendProps>) {
    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );
    const defined = useMemo(
        () => windowed.filter((p) => p.vdot !== null),
        [windowed],
    );

    const labels = useMemo(
        () => windowed.map((p) => formatNaiveIdDate(p.date, 'short')),
        [windowed],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'VDOT',
                    data: windowed.map((p) => p.vdot),
                    borderColor: PALETTE.horizonInk,
                    backgroundColor: `${PALETTE.horizon}2e`,
                    borderWidth: 2.5,
                    pointRadius: 0,
                    tension: 0.3,
                    spanGaps: true,
                    fill: true,
                },
            ],
        }),
        [windowed, labels],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 900, easing: 'easeOutQuart' as const },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ dataIndex: number }>) =>
                            windowed[items[0]?.dataIndex ?? 0]
                                ? formatNaiveIdDate(
                                      windowed[items[0]?.dataIndex ?? 0].date,
                                      'short',
                                  )
                                : '',
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { display: false } },
                y: {
                    grid: { color: GRID_LINE },
                    ticks: { color: PALETTE.ink2, font: { size: 12 } },
                },
            },
        }),
        [windowed],
    );

    const latest = defined.length > 0 ? defined[defined.length - 1] : null;
    const first = defined.length > 0 ? defined[0] : null;
    const vdotCount = useCountUp(latest?.vdot ?? 0);
    const change =
        latest !== null && first !== null ? latest.vdot! - first.vdot! : null;

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="Not enough VDOT history yet."
                body="This builds up day by day from your personal records — check back after your next few runs."
                className={cn('rounded-(--radius-panel)', className)}
            />
        );
    }

    const summarySentence =
        first !== null && latest !== null
            ? `VDOT went from ${first.vdot!.toFixed(1)} to ${latest.vdot!.toFixed(1)}.`
            : 'Not enough VDOT history yet to show a trend.';

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-(--radius-panel) border border-line bg-surface-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-ink-3">Fitness score</p>
                <h2 className="mt-1 font-display text-lg text-ink">
                    VDOT History
                </h2>
                <p className="mt-1 text-sm text-ink-2">
                    VDOT is a single running fitness number worked out from your
                    best effort, using the Jack Daniels formula. Higher means
                    you are holding a faster pace for the same cost.
                </p>
            </div>

            <motion.div
                initial="hidden"
                animate="visible"
                variants={staggerContainer}
                className="grid grid-cols-3 gap-2 sm:gap-3"
            >
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="VDOT now"
                        value={latest !== null ? vdotCount.toFixed(1) : '—'}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Over this window"
                        value={
                            change !== null
                                ? `${change >= 0 ? '+' : ''}${change.toFixed(1)}`
                                : '—'
                        }
                        sub={
                            change === null
                                ? undefined
                                : change > 0
                                  ? 'Moving up'
                                  : change < 0
                                    ? 'Moving down'
                                    : 'Flat'
                        }
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Set by"
                        value={sourceCategory ?? '—'}
                        sub={
                            sourceCategory !== null
                                ? 'Your slowest-scoring PR'
                                : undefined
                        }
                    />
                </motion.div>
            </motion.div>

            <motion.div
                role="img"
                aria-label={`VDOT over ${windowed.length} days. ${summarySentence}`}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="h-[220px] sm:h-[280px]"
            >
                <span className="sr-only">{summarySentence}</span>
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-xl" />}
                >
                    <Line data={data} options={options} />
                </Suspense>
            </motion.div>
        </div>
    );
}
