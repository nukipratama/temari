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

// Chart.js core + its scale/element registration live inside this lazy
// module, mirroring CtlTrendChart/ProgressionChart so nothing chart-related
// enters this page's own chunk either.
const Line = lazy(() => import('@/components/collection/LineChart'));

export interface FitnessTrendPoint {
    date: string;
    atl: number;
    ctl: number;
}

interface FitnessTrendProps {
    trend: ReadonlyArray<FitnessTrendPoint>;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

const CTL_FILL = `${PALETTE.horizon}2e`; // 0.18 alpha
const GRID_LINE = `${PALETTE.ink3}1f`; // 0.12 alpha

function fitnessHint(ctl: number): string {
    if (ctl < 25) return 'Still building';
    if (ctl < 50) return 'Trending up';
    if (ctl < 80) return 'Stable';
    return 'High';
}

function fatigueHint(atl: number): string {
    if (atl < 25) return 'Fresh';
    if (atl < 55) return 'Normal';
    if (atl < 85) return 'Tired';
    return 'Heavy';
}

/**
 * Fitness/Fatigue panel — the first Trends panel ported for real, proving the
 * backend-to-frontend plumbing end to end since TrainingLoad::ctlTrend()
 * already exists. Badge milestones on the timeline are deferred to the slice
 * that also ports Personal Bests (same new date-joined badge query backs
 * both), so this renders the two lines and their headline stats only.
 */
export default function FitnessTrend({
    trend,
    range,
    className,
}: Readonly<FitnessTrendProps>) {
    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
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
                    label: 'Fitness',
                    data: windowed.map((p) => p.ctl),
                    borderColor: PALETTE.horizon,
                    backgroundColor: CTL_FILL,
                    borderWidth: 2.5,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: true,
                },
                {
                    label: 'Fatigue',
                    data: windowed.map((p) => p.atl),
                    borderColor: PALETTE.ink3,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false,
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
                        title: (items: Array<{ dataIndex: number }>) => {
                            const i = items[0]?.dataIndex ?? 0;
                            return windowed[i]
                                ? formatNaiveIdDate(windowed[i].date, 'short')
                                : '';
                        },
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

    const latest = windowed[windowed.length - 1];
    const ctlCount = useCountUp(latest?.ctl ?? 0);
    const atlCount = useCountUp(latest?.atl ?? 0);
    const formCount = useCountUp((latest?.ctl ?? 0) - (latest?.atl ?? 0));

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="Not enough training history yet to draw a trend."
                className={cn('rounded-(--radius-panel)', className)}
            />
        );
    }

    const summarySentence = `Fitness ${windowed[0].ctl.toFixed(0)} to ${latest.ctl.toFixed(0)} over ${windowed.length} days, fatigue now ${latest.atl.toFixed(0)}.`;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-(--radius-panel) border border-line bg-surface-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-ink-3">Load</p>
                <h2 className="mt-1 font-display text-lg text-ink">
                    Fitness and Fatigue
                </h2>
                <p className="mt-1 text-sm text-ink-2">
                    Fitness is your training load averaged over a long window,
                    fatigue over a short one. When the fitness line climbs and
                    the fatigue line sits under it, the work is sticking.
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
                        label="Fitness"
                        value={Math.round(ctlCount)}
                        unit="CTL"
                        sub={fitnessHint(latest.ctl)}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Fatigue"
                        value={Math.round(atlCount)}
                        unit="ATL"
                        sub={fatigueHint(latest.atl)}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Form"
                        value={
                            formCount >= 0
                                ? `+${Math.round(formCount)}`
                                : Math.round(formCount)
                        }
                        sub={
                            latest.ctl - latest.atl >= 0
                                ? 'Rested'
                                : 'Carrying load'
                        }
                    />
                </motion.div>
            </motion.div>

            <motion.div
                role="img"
                aria-label={`Fitness and fatigue over ${windowed.length} days. ${summarySentence}`}
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
