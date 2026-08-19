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
import {
    monotonyHint,
    monotonyTone,
    strainHint,
    strainTone,
} from '@/pages/Home/helpers';

import type { TrendRange } from '../RangeToggle';

const Line = lazy(() => import('@/components/collection/LineChart'));

export interface LoadTrendPoint {
    date: string;
    weekly_trimp: number | null;
    monotony: number | null;
    strain: number | null;
}

interface LoadTrendProps {
    trend: ReadonlyArray<LoadTrendPoint>;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

const GRID_LINE = `${PALETTE.ink3}1f`; // 0.12 alpha

function miniChartOptions(labels: string[], decimals: number) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 900, easing: 'easeOutQuart' as const },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: (items: Array<{ dataIndex: number }>) =>
                        labels[items[0]?.dataIndex ?? 0] ?? '',
                    label: (item: { parsed: { y: number | null } }) =>
                        item.parsed.y == null
                            ? 'No run that week'
                            : item.parsed.y.toFixed(decimals),
                },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { display: false } },
            y: {
                grid: { color: GRID_LINE },
                ticks: { color: PALETTE.ink2, font: { size: 11 } },
            },
        },
    };
}

/**
 * Load quality panel — strain (weekly load × monotony) and monotony
 * (how same-y the week's daily loads were) as two mini charts, since the two
 * metrics live on different scales. Renders the daily trailing-7-day series
 * TrainingLoad::strainMonotonyTrend() already produces directly, at full
 * density — no weekly downsampling.
 */
export default function LoadTrend({
    trend,
    range,
    className,
}: Readonly<LoadTrendProps>) {
    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );
    const scored = useMemo(
        () => windowed.filter((p) => p.strain !== null && p.monotony !== null),
        [windowed],
    );

    const labels = useMemo(
        () => windowed.map((p) => formatNaiveIdDate(p.date, 'short')),
        [windowed],
    );

    const strainData = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'Strain',
                    data: windowed.map((p) => p.strain),
                    borderColor: PALETTE.horizonInk,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                },
            ],
        }),
        [windowed, labels],
    );

    const monotonyData = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'Monotony',
                    data: windowed.map((p) => p.monotony),
                    borderColor: PALETTE.ink3,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    tension: 0.3,
                },
            ],
        }),
        [windowed, labels],
    );

    const strainOptions = useMemo(() => miniChartOptions(labels, 0), [labels]);
    const monotonyOptions = useMemo(
        () => miniChartOptions(labels, 2),
        [labels],
    );

    const latest = scored.length > 0 ? scored[scored.length - 1] : null;
    const strainCount = useCountUp(latest?.strain ?? 0);
    const monotonyCount = useCountUp(latest?.monotony ?? 0);
    const peakStrain =
        scored.length > 0
            ? Math.max(...scored.map((p) => p.strain ?? 0))
            : null;

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="Not enough training history yet to draw load quality."
                className={cn('rounded-(--radius-panel)', className)}
            />
        );
    }

    const summarySentence =
        latest !== null
            ? `Strain ${latest.strain!.toFixed(0)}, monotony ${latest.monotony!.toFixed(2)} over the trailing week.`
            : 'No heart-rate data in this window to score load quality.';

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-(--radius-panel) border border-line bg-surface-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-ink-3">Load quality</p>
                <h2 className="mt-1 font-display text-lg text-ink">
                    Strain and Monotony
                </h2>
                <p className="mt-1 text-sm text-ink-2">
                    Monotony is how same-y your week looked, strain is that
                    sameness multiplied by how much you did. A hard week is
                    fine. A hard week where every day looked identical is the
                    one that bites.
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
                        label="Strain"
                        value={latest !== null ? Math.round(strainCount) : '—'}
                        sub={
                            latest !== null
                                ? strainHint(latest.strain)
                                : 'No HR on these runs'
                        }
                        valueClassName={
                            latest !== null
                                ? strainTone(latest.strain)
                                : undefined
                        }
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Monotony"
                        value={latest !== null ? monotonyCount.toFixed(2) : '—'}
                        sub={
                            latest !== null
                                ? monotonyHint(latest.monotony)
                                : 'No HR on these runs'
                        }
                        valueClassName={
                            latest !== null
                                ? monotonyTone(latest.monotony)
                                : undefined
                        }
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Peak strain"
                        value={
                            peakStrain !== null ? Math.round(peakStrain) : '—'
                        }
                        sub="In this window"
                    />
                </motion.div>
            </motion.div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <motion.div
                    role="img"
                    aria-label={`Strain over ${windowed.length} days. ${summarySentence}`}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                >
                    <p className="mb-2 text-xs text-ink-3">
                        Weekly load times monotony.
                    </p>
                    <span className="sr-only">{summarySentence}</span>
                    <div className="h-[160px] sm:h-[200px]">
                        <Suspense
                            fallback={
                                <Skeleton className="h-full w-full rounded-xl" />
                            }
                        >
                            <Line data={strainData} options={strainOptions} />
                        </Suspense>
                    </div>
                </motion.div>
                <motion.div
                    role="img"
                    aria-label={`Monotony over ${windowed.length} days. ${summarySentence}`}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.05 }}
                >
                    <p className="mb-2 text-xs text-ink-3">
                        Weekly mean load divided by its spread.
                    </p>
                    <span className="sr-only">{summarySentence}</span>
                    <div className="h-[160px] sm:h-[200px]">
                        <Suspense
                            fallback={
                                <Skeleton className="h-full w-full rounded-xl" />
                            }
                        >
                            <Line
                                data={monotonyData}
                                options={monotonyOptions}
                            />
                        </Suspense>
                    </div>
                </motion.div>
            </div>

            <p className="text-xs text-ink-3">
                Gaps in these lines are days with no run to score, not a
                monotony of zero.
            </p>
        </div>
    );
}
