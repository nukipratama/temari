import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend, type TooltipItem } from 'chart.js';
import { formatNumericTooltip, kmAxisTick, tooltipFromTheme, useChartTheme } from '@/lib/chartTheme';
import type { FitnessChartData } from '@/types/inertia';

/** Chart.js tooltip label formatter — named for direct testability. */
export function volumeTooltipLabel(ctx: TooltipItem<'bar'>): string {
    return formatNumericTooltip('Volume', ctx.parsed.y, 'km');
}

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface VolumeChartProps {
    data: FitnessChartData;
}

const BAR_FILL = '#d9a03c';

export default function VolumeChart({ data }: Readonly<VolumeChartProps>) {
    const theme = useChartTheme();
    const numericVolume = data.volume.filter((v): v is number => v !== null);
    const total = numericVolume.reduce((sum, v) => sum + v, 0);
    const max = numericVolume.length === 0 ? null : Math.max(...numericVolume);

    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-5 dark:border-line-dark dark:bg-surface-dark-elev">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                    Weekly Volume
                </h3>
                <span className="text-[10px] text-ink-meta dark:text-ink-meta-dark">hover untuk detail</span>
            </div>

            <dl className="mt-3 grid grid-cols-2 gap-3">
                <Stat label="Total" value={`${total.toFixed(1)} km`} desc={`${numericVolume.length} minggu`} />
                <Stat label="Puncak" value={max === null ? '—' : `${max.toFixed(1)} km`} desc="minggu terberat" />
            </dl>

            <div className="relative mt-4 h-56">
                <Bar
                    data={{
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'Volume',
                                data: data.volume,
                                backgroundColor: `${BAR_FILL}66`,
                                hoverBackgroundColor: BAR_FILL,
                                borderColor: BAR_FILL,
                                borderWidth: 1,
                                borderRadius: 4,
                            },
                        ],
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                ...tooltipFromTheme(theme),
                                callbacks: { label: volumeTooltipLabel },
                            },
                        },
                        scales: {
                            x: {
                                ticks: { font: { size: 10 }, color: theme.tick, maxRotation: 0, autoSkipPadding: 14 },
                                grid: { color: theme.grid },
                            },
                            y: {
                                ticks: { font: { size: 10 }, color: theme.tick, callback: kmAxisTick },
                                grid: { color: theme.grid },
                            },
                        },
                    }}
                />
            </div>
        </div>
    );
}

function Stat({ label, value, desc }: Readonly<{ label: string; value: string; desc: string }>) {
    return (
        <div className="rounded-xl bg-line/20 p-2 dark:bg-line-dark/40">
            <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                {label}
            </dt>
            <dd className="mt-0.5 text-base font-bold tabular-nums text-ink dark:text-ink-dark">{value}</dd>
            <dd className="text-[10px] text-ink-meta dark:text-ink-meta-dark">{desc}</dd>
        </div>
    );
}
