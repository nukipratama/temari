import type { Plugin } from 'chart.js';

import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { useIsChartDark } from '@/hooks/useIsChartDark';
import { CHART_GROUND, PALETTE } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';

import type { TrendRange } from '../RangeToggle';

const Line = lazy(() => import('@/components/collection/LineChart'));

export interface PaceConsistencyPoint {
    date: string;
    variabilitySec: number | null;
}

interface PaceConsistencyTrendProps {
    trend: ReadonlyArray<PaceConsistencyPoint>;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

// Mirrors PaceConsistency.php's VERY_EVEN_SEC/EVEN_SEC/UNEVEN_SEC bands.
const BANDS = [
    { max: 8, label: 'Very steady', tone: 'text-leaf-ink' },
    { max: 15, label: 'Fairly steady', tone: 'text-leaf-ink' },
    { max: 20, label: 'A bit up and down', tone: 'text-citrus-ink' },
    { max: Infinity, label: 'Up and down', tone: 'text-ember-ink' },
] as const;

function bandFor(sec: number): (typeof BANDS)[number] {
    return BANDS.find((b) => sec <= b.max) ?? BANDS[BANDS.length - 1];
}

const CHART_MAX = 26;
const BAND_TINTS = [
    `${PALETTE.leaf}14`,
    `${PALETTE.leaf}0a`,
    `${PALETTE.citrus}14`,
    `${PALETTE.ember}14`,
];

const bandBackground: Plugin<'line'> = {
    id: 'consistencyBandBackground',
    beforeDatasetsDraw(chart) {
        const { ctx, chartArea, scales } = chart;
        if (!chartArea) return;
        const y = scales.y;
        const boundaries = [0, 8, 15, 20, CHART_MAX];
        ctx.save();
        for (let i = 0; i < boundaries.length - 1; i++) {
            const top = y.getPixelForValue(boundaries[i + 1]);
            const bottom = y.getPixelForValue(boundaries[i]);
            ctx.fillStyle = BAND_TINTS[i];
            ctx.fillRect(
                chartArea.left,
                top,
                chartArea.right - chartArea.left,
                bottom - top,
            );
        }
        ctx.restore();
    },
};

/**
 * Pace Consistency panel — how far apart km splits sit inside a single run,
 * averaged per day (TrendDailySnapshot.pace_variability_sec). Grow-forward
 * only, same as VdotTrend. Band thresholds mirror PaceConsistency.php.
 */
export default function PaceConsistencyTrend({
    trend,
    range,
    className,
}: Readonly<PaceConsistencyTrendProps>) {
    const isDark = useIsChartDark();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;
    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );
    const defined = useMemo(
        () => windowed.filter((p) => p.variabilitySec !== null),
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
                    label: 'Pace consistency',
                    data: windowed.map((p) => p.variabilitySec),
                    borderColor: ground.line,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    spanGaps: true,
                },
            ],
        }),
        [windowed, labels, ground.line],
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
                        label: (item: { parsed: { y: number | null } }) =>
                            item.parsed.y == null
                                ? 'No data'
                                : `${item.parsed.y.toFixed(1)}s spread · ${bandFor(item.parsed.y).label.toLowerCase()}`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { display: false } },
                y: {
                    suggestedMin: 0,
                    suggestedMax: CHART_MAX,
                    grid: { color: ground.grid },
                    ticks: {
                        color: ground.tick,
                        font: { size: 12 },
                        callback: (v: number | string) => `${v}s`,
                    },
                },
            },
        }),
        [windowed, ground],
    );

    const latest = defined.length > 0 ? defined[defined.length - 1] : null;
    const first = defined.length > 0 ? defined[0] : null;
    const steadiest =
        defined.length > 0
            ? Math.min(...defined.map((p) => p.variabilitySec!))
            : null;
    const latestCount = useCountUp(latest?.variabilitySec ?? 0);

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="Not enough pace history yet."
                body="This builds up day by day from your runs' km splits — check back after your next few runs."
                className={cn('rounded-(--radius-panel)', className)}
            />
        );
    }

    const latestBand = latest !== null ? bandFor(latest.variabilitySec!) : null;
    const summarySentence =
        first !== null && latest !== null
            ? `Split spread went from ${first.variabilitySec!.toFixed(1)} to ${latest.variabilitySec!.toFixed(1)} seconds.`
            : 'Not enough pace history yet to show a trend.';

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-(--radius-panel) border border-border bg-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-text-3">Pacing</p>
                <h2 className="mt-1 font-serif text-lg text-foreground">
                    Pace Consistency
                </h2>
                <p className="mt-1 text-sm text-text-2">
                    How far apart your kilometre splits sit inside a single run,
                    in seconds. Lower is steadier, which usually means you
                    judged the effort well rather than going out hot.
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
                        label="This week"
                        value={
                            latest !== null ? `${latestCount.toFixed(1)}s` : '—'
                        }
                        sub={latestBand?.label}
                        valueClassName={latestBand?.tone}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="A year ago"
                        value={
                            first !== null
                                ? `${first.variabilitySec!.toFixed(1)}s`
                                : '—'
                        }
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Steadiest"
                        value={
                            steadiest !== null
                                ? `${steadiest.toFixed(1)}s`
                                : '—'
                        }
                        valueClassName="text-leaf-ink"
                    />
                </motion.div>
            </motion.div>

            <motion.div
                role="img"
                aria-label={`Pace consistency over ${windowed.length} days. ${summarySentence}`}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="h-[220px] sm:h-[280px]"
            >
                <span className="sr-only">{summarySentence}</span>
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-xl" />}
                >
                    <Line
                        data={data}
                        options={options}
                        plugins={[bandBackground]}
                    />
                </Suspense>
            </motion.div>
        </div>
    );
}
