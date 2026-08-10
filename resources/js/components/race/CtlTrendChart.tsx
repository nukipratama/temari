import { lazy, Suspense, useMemo } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import { cn } from '@/lib/cn';
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

// Daybreak tokens resolved to hex — Chart.js paints to canvas and can't read
// CSS custom properties. Keep in sync with the @theme block in app.css.
const CHART_TOKENS = {
    horizon: '#e8a076', // --color-horizon (CTL / fitness — the slow line)
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
        <div
            role="img"
            aria-label={`90-day fitness and fatigue trend. ${summarySentence}`}
            className={cn('h-[240px] sm:h-[280px]', className)}
        >
            <span className="sr-only">{summarySentence}</span>
            <Suspense
                fallback={<Skeleton className="h-full w-full rounded-xl" />}
            >
                <Line data={data} options={options} />
            </Suspense>
        </div>
    );
}
