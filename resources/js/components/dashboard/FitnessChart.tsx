import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
    type TooltipItem,
} from 'chart.js';
import { formatNumericTooltip, tooltipFromTheme, useChartTheme } from '@/lib/chartTheme';
import { DAYBREAK } from '@/lib/chartTokens';
import type { FitnessChartData } from '@/types/inertia';

export function fitnessTooltipLabel(ctx: TooltipItem<'line'>): string {
    return formatNumericTooltip(ctx.dataset.label ?? '', ctx.parsed.y);
}

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

interface FitnessChartProps {
    data: FitnessChartData;
}

const SERIES = [
    { key: 'ctl', label: 'CTL', color: DAYBREAK.leaf, desc: 'Fitness 42-hari', fill: true, dash: false, width: 2 },
    { key: 'atl', label: 'ATL', color: DAYBREAK.ember, desc: 'Fatigue 7-hari', fill: true, dash: false, width: 2 },
    { key: 'form', label: 'Form', color: DAYBREAK.mumet, desc: 'CTL − ATL', fill: false, dash: true, width: 1.8 },
] as const;

export default function FitnessChart({ data }: Readonly<FitnessChartProps>) {
    const theme = useChartTheme();

    const latest = {
        ctl: lastNonNull(data.ctl),
        atl: lastNonNull(data.atl),
        form: lastNonNull(data.form),
    };

    return (
        <div className="rounded-2xl border border-line bg-surface-card p-5">
            <div className="flex items-center justify-between gap-3">
                <h3 className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                    Fitness &amp; Form
                </h3>
                <span className="text-[11px] text-ink-3">hover untuk detail</span>
            </div>

            <dl className="mt-3 grid grid-cols-3 gap-3">
                {SERIES.map((s) => (
                    <div key={s.key} className="rounded-xl bg-line/20 p-2">
                        <dt className="flex items-center gap-1.5 font-mono text-[11px] font-bold uppercase tracking-wider text-ink-2">
                            <span aria-hidden className="inline-block h-2 w-2 rounded-full" style={{ background: s.color }} />
                            {s.label}
                        </dt>
                        <dd className="mt-0.5 font-mono text-base font-bold tabular-nums text-ink">
                            {latest[s.key as keyof typeof latest]?.toFixed(1) ?? '—'}
                        </dd>
                        <dd className="text-[11px] text-ink-2">{s.desc}</dd>
                    </div>
                ))}
            </dl>

            <div className="relative mt-4 h-56">
                <Line
                    data={{
                        labels: data.labels,
                        datasets: SERIES.map((s) => ({
                            label: s.label,
                            data: data[s.key as keyof FitnessChartData] as (number | null)[],
                            borderColor: s.color,
                            // Stack ATL (top, drawn second) over CTL so the
                            // overlap reads as two distinct tinted regions
                            // rather than the deeper colour erasing the lighter.
                            backgroundColor: s.fill ? `${s.color}33` : undefined,
                            fill: s.fill ? 'origin' : false,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: s.color,
                            pointHoverBorderColor: theme.tooltip.backgroundColor,
                            pointHoverBorderWidth: 2,
                            borderDash: s.dash ? [4, 4] : undefined,
                            borderWidth: s.width,
                        })),
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                ...tooltipFromTheme(theme),
                                callbacks: { label: fitnessTooltipLabel },
                            },
                        },
                        scales: {
                            x: {
                                ticks: { font: { size: 11 }, color: theme.tick, maxRotation: 0, autoSkipPadding: 14 },
                                grid: { color: theme.grid },
                            },
                            y: {
                                ticks: { font: { size: 11 }, color: theme.tick },
                                grid: { color: theme.grid },
                            },
                        },
                    }}
                />
            </div>
        </div>
    );
}

function lastNonNull(arr: (number | null)[]): number | null {
    for (let i = arr.length - 1; i >= 0; i--) {
        const v = arr[i];
        if (v !== null) return v;
    }
    return null;
}
