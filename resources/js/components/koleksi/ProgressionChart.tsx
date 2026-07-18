import { lazy, Suspense, useMemo } from 'react';
import { cn } from '@/lib/cn';
import EmptyState from '@/components/ui/EmptyState';
import Skeleton from '@/components/ui/Skeleton';
import { formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';

const Line = lazy(() => import('react-chartjs-2').then((m) => ({ default: m.Line })));

interface ProgressionChartProps {
    weeks: ReadonlyArray<string>;
    timesSec: ReadonlyArray<number | null>;
    goalSec: number | null;
    /** Category name shown in the chart's accessible label, e.g. "5K". */
    category?: string;
    className?: string;
}

// Side-effect register Chart.js. Top-level so the import is paid once
// even though Line is lazy-loaded.
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend);

// Daybreak tokens resolved to the hex Chart.js needs (it paints to canvas and
// can't read the CSS custom properties). Keep in sync with the @theme block in
// resources/css/app.css. `#RRGGBBAA` suffixes are the token color at a set alpha.
const CHART_TOKENS = {
    horizon: '#e8a076', // --color-horizon (best-time line + area fill)
    horizonDeep: '#d08a60', // --color-horizon-deep (point fill)
    cream: '#f6f1e8', // --color-cream (point border)
    citrus: '#d9b23a', // --color-citrus (goal line / PR accent)
    ink2: '#3d362a', // --color-ink-2 (axis ticks)
    ink3: '#6e6452', // --color-ink-3 (grid line)
} as const;
const HORIZON_FILL_FLAT = `${CHART_TOKENS.horizon}2e`; // 0.18 alpha
const HORIZON_FILL_TOP = `${CHART_TOKENS.horizon}52`; // 0.32 alpha
const HORIZON_FILL_BOTTOM = `${CHART_TOKENS.horizon}05`; // 0.02 alpha
const GRID_LINE = `${CHART_TOKENS.ink3}1f`; // 0.12 alpha

function lastDefinedIndex(values: ReadonlyArray<number | null>): number {
    for (let i = values.length - 1; i >= 0; i--) {
        if (values[i] != null) return i;
    }
    return -1;
}

export default function ProgressionChart({
    weeks,
    timesSec,
    goalSec,
    category,
    className,
}: Readonly<ProgressionChartProps>) {
    const chartLabel = category ? `Grafik progresi waktu terbaik ${category}` : 'Grafik progresi waktu terbaik';
    const firstIdx = timesSec.findIndex((t) => t != null);
    const lastIdx = lastDefinedIndex(timesSec);
    const summarySentence =
        firstIdx >= 0 && lastIdx >= 0
            ? `Dari ${formatDurationHMS(timesSec[firstIdx]!)} pada ${formatNaiveIdDate(weeks[firstIdx], 'short')} menjadi ${formatDurationHMS(timesSec[lastIdx]!)} pada ${formatNaiveIdDate(weeks[lastIdx], 'short')}.`
            : 'Belum ada data waktu untuk periode ini.';
    // Space points by their real date (day-offset from the first week), not at even
    // intervals, so uneven time gaps read honestly instead of overstating progress.
    const xOffsets = useMemo(() => {
        const baseMs = weeks.length > 0 ? Date.parse(weeks[0]) : 0;
        return weeks.map((w) => (Date.parse(w) - baseMs) / 86_400_000);
    }, [weeks]);

    const data = useMemo(() => ({
        labels: weeks.map((w) => formatNaiveIdDate(w, 'short')),
        datasets: [
            {
                label: 'Best time',
                data: timesSec.map((t, i) => (t == null ? null : { x: xOffsets[i], y: t / 60 })),
                borderColor: CHART_TOKENS.horizon,
                // Vertical gradient area fill (denser near the line, fading to the axis)
                // instead of a flat wash, so the chart reads as intentional, not a default.
                backgroundColor: (ctx: { chart: { chartArea?: { top: number; bottom: number }; ctx: CanvasRenderingContext2D } }) => {
                    const { chartArea, ctx: canvasCtx } = ctx.chart;
                    if (!chartArea) return HORIZON_FILL_FLAT;
                    const g = canvasCtx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    g.addColorStop(0, HORIZON_FILL_TOP);
                    g.addColorStop(1, HORIZON_FILL_BOTTOM);
                    return g;
                },
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: CHART_TOKENS.horizonDeep,
                pointBorderColor: CHART_TOKENS.cream,
                pointBorderWidth: 1.5,
                tension: 0.32,
                fill: true,
                spanGaps: true,
            },
            ...(goalSec
                ? [
                      {
                          label: 'Goal',
                          // Flat line spanning the full time range (2 points, not one
                          // per week) so its x still aligns with the time-scaled axis.
                          data: xOffsets.length > 0
                              ? [
                                    { x: xOffsets[0], y: goalSec / 60 },
                                    { x: xOffsets.at(-1)!, y: goalSec / 60 },
                                ]
                              : [],
                          borderColor: CHART_TOKENS.citrus,
                          backgroundColor: 'transparent',
                          borderDash: [6, 6],
                          borderWidth: 1.5,
                          pointRadius: 0,
                          tension: 0,
                          fill: false,
                      },
                  ]
                : []),
        ],
    }), [weeks, timesSec, goalSec, xOffsets]);

    const options = useMemo(() => {
        const xMin = xOffsets.length > 0 ? xOffsets[0] : 0;
        const lastX = xOffsets.length > 0 ? xOffsets.at(-1)! : 0;
        const xMax = lastX > xMin ? lastX : xMin + 1;
        return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: (items: Array<{ dataIndex: number }>) => {
                        const i = items[0]?.dataIndex ?? 0;
                        return weeks[i] ? formatNaiveIdDate(weeks[i], 'short') : '';
                    },
                    label: (ctx: { dataset: { label?: string }; parsed: { y: number | null } }) => {
                        const v = ctx.parsed.y;
                        if (v == null) return '';
                        return `${ctx.dataset.label}: ${formatDurationHMS(Math.round(v * 60))}`;
                    },
                },
            },
        },
        scales: {
            x: {
                type: 'linear' as const,
                min: xMin,
                max: xMax,
                grid: { display: false },
                // Date labels collide on narrow phones; the date lives in the tooltip instead.
                ticks: { display: false },
            },
            y: {
                reverse: true,
                grid: { color: GRID_LINE },
                ticks: {
                    color: CHART_TOKENS.ink2,
                    font: { size: 12 },
                    callback: (val: number | string) => {
                        const v = typeof val === 'number' ? val : Number(val);
                        return Number.isFinite(v) ? formatDurationHMS(Math.round(v * 60)) : String(val);
                    },
                },
            },
        },
        };
    }, [weeks, xOffsets]);

    if (weeks.length === 0) {
        return (
            <EmptyState className={cn('py-10', className)}>
                Belum cukup lari di jarak ini buat narik garis progresi.
            </EmptyState>
        );
    }

    return (
        <div role="img" aria-label={`${chartLabel}. ${summarySentence}`} className={cn('h-[260px] sm:h-[300px]', className)}>
            <span className="sr-only">{summarySentence}</span>
            <Suspense fallback={<Skeleton className="h-full w-full rounded-xl" />}>
                <Line data={data} options={options} />
            </Suspense>
        </div>
    );
}
