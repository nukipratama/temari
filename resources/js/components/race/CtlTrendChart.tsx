import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';

// Chart.js core + its scale/element registration live inside this lazy module,
// mirroring ProgressionChart so nothing chart-related enters this page's own
// chunk either.
const Line = lazy(() => import('@/components/koleksi/LineChart'));

export interface CtlTrendPoint {
    date: string;
    atl: number;
    ctl: number;
}

interface CtlTrendChartProps {
    trend: ReadonlyArray<CtlTrendPoint>;
    className?: string;
}

// Design tokens resolved to hex — Chart.js paints to canvas and can't read
// CSS custom properties. Keep in sync with the @theme block in app.css.
const CHART_TOKENS = {
    horizon: '#d9a53c', // --color-horizon (CTL / fitness — the slow line)
    ink2: '#3d362a', // --color-ink-2 (axis ticks)
    ink3: '#6e6452', // --color-ink-3 (grid line + ATL / fatigue line)
} as const;
const CTL_FILL = `${CHART_TOKENS.horizon}2e`; // 0.18 alpha
const GRID_LINE = `${CHART_TOKENS.ink3}1f`; // 0.12 alpha

export default function CtlTrendChart({
    trend,
    className,
}: Readonly<CtlTrendChartProps>) {
    const labels = useMemo(
        () => trend.map((p) => formatNaiveIdDate(p.date, 'short')),
        [trend],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'Fitness (CTL)',
                    data: trend.map((p) => p.ctl),
                    borderColor: CHART_TOKENS.horizon,
                    backgroundColor: CTL_FILL,
                    borderWidth: 2.5,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: true,
                },
                {
                    label: 'Fatigue (ATL)',
                    data: trend.map((p) => p.atl),
                    borderColor: CHART_TOKENS.ink3,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false,
                },
            ],
        }),
        [trend, labels],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 900,
                easing: 'easeOutQuart' as const,
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top' as const,
                    labels: { color: CHART_TOKENS.ink2, boxWidth: 12 },
                },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ dataIndex: number }>) => {
                            const i = items[0]?.dataIndex ?? 0;
                            return trend[i]
                                ? formatNaiveIdDate(trend[i].date, 'short')
                                : '';
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { display: false },
                },
                y: {
                    grid: { color: GRID_LINE },
                    ticks: { color: CHART_TOKENS.ink2, font: { size: 12 } },
                },
            },
        }),
        [trend],
    );

    const latest = trend[trend.length - 1];
    const ctlCount = useCountUp(latest?.ctl ?? 0);
    const atlCount = useCountUp(latest?.atl ?? 0);

    if (trend.length === 0) {
        return (
            <EmptyPanel
                title="Not enough training history yet to draw a trend."
                className={cn('py-10', className)}
            />
        );
    }

    const summarySentence = `Fitness ${trend[0].ctl.toFixed(0)} to ${trend[trend.length - 1].ctl.toFixed(0)} over ${trend.length} days.`;

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            <motion.div
                initial="hidden"
                animate="visible"
                variants={staggerContainer}
                className="flex flex-wrap gap-4"
            >
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Fitness now"
                        value={Math.round(ctlCount)}
                        unit="CTL"
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Fatigue now"
                        value={Math.round(atlCount)}
                        unit="ATL"
                    />
                </motion.div>
            </motion.div>
            <motion.div
                role="img"
                aria-label={`90-day fitness and fatigue trend. ${summarySentence}`}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="h-[240px] sm:h-[280px]"
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
