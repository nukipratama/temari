import { lazy, Suspense, useMemo } from 'react';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';

const Line = lazy(() => import('react-chartjs-2').then((m) => ({ default: m.Line })));

interface ProgressionChartProps {
    weeks: ReadonlyArray<string>;
    timesSec: ReadonlyArray<number | null>;
    goalSec: number | null;
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

export default function ProgressionChart({
    weeks,
    timesSec,
    goalSec,
    className,
}: Readonly<ProgressionChartProps>) {
    const data = useMemo(() => ({
        labels: weeks.map((w) => formatNaiveIdDate(w, 'short')),
        datasets: [
            {
                label: 'Best time',
                data: timesSec.map((t) => (t == null ? null : t / 60)),
                borderColor: 'rgba(232, 160, 118, 1)',
                backgroundColor: 'rgba(232, 160, 118, 0.18)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: 'rgba(208, 138, 96, 1)',
                pointBorderColor: 'rgba(246, 241, 232, 1)',
                pointBorderWidth: 1.5,
                tension: 0.32,
                fill: true,
                spanGaps: true,
            },
            ...(goalSec
                ? [
                      {
                          label: 'Goal',
                          data: weeks.map(() => goalSec / 60),
                          borderColor: 'rgba(217, 178, 58, 1)',
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
    }), [weeks, timesSec, goalSec]);

    const options = useMemo(() => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
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
                grid: { display: false },
                ticks: { color: '#3d362a', font: { size: 12 } },
            },
            y: {
                reverse: true,
                grid: { color: 'rgba(122, 111, 92, 0.12)' },
                ticks: {
                    color: '#3d362a',
                    font: { size: 12 },
                    callback: (val: number | string) => {
                        const v = typeof val === 'number' ? val : Number(val);
                        return Number.isFinite(v) ? formatDurationHMS(Math.round(v * 60)) : String(val);
                    },
                },
            },
        },
    }), []);

    if (weeks.length === 0) {
        return (
            <div className={cn('rounded-2xl border-2 border-dashed border-cream-deep bg-cream/40 px-6 py-10 text-center font-display text-base italic text-ink-3', className)}>
                Belum cukup lari di jarak ini buat narik garis progresi.
            </div>
        );
    }

    return (
        <div className={cn('h-[260px] sm:h-[300px]', className)}>
            <Suspense fallback={<div className="h-full w-full animate-pulse rounded-xl bg-cream-deep/40" />}>
                <Line data={data} options={options} />
            </Suspense>
        </div>
    );
}
