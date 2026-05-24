import { useMemo } from 'react';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend, type TooltipItem } from 'chart.js';
import { formatNumericTooltip, kmAxisTick, tooltipFromTheme, useChartTheme } from '@/lib/chartTheme';
import type { FitnessChartData } from '@/types/inertia';

export function volumeTooltipLabel(ctx: TooltipItem<'bar'>): string {
    return formatNumericTooltip('Volume', ctx.parsed.y, 'km');
}

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface VolumeChartProps {
    data: FitnessChartData;
}

const BAR_FILL = '#d9764a';
const BAR_FILL_DARK = '#8c4727';
const BAR_FILL_LIGHT = '#eab397';

// Intensity-coded fill — fades from lighter accent on the lowest week
// to deeper terracotta on the heaviest. Adds a second visual encoding
// beyond bar height, so a tall+dark bar reads "heavy week" at a glance.
function intensityFill(value: number | null, max: number | null): string {
    if (value === null || max === null || max === 0) return `${BAR_FILL}66`;
    const ratio = value / max;
    if (ratio >= 0.85) return BAR_FILL_DARK;
    if (ratio >= 0.6) return BAR_FILL;
    if (ratio >= 0.3) return `${BAR_FILL}AA`;
    return BAR_FILL_LIGHT;
}

export default function VolumeChart({ data }: Readonly<VolumeChartProps>) {
    const theme = useChartTheme();
    const { total, max, intensityFills, sampleCount } = useMemo(() => {
        let totalSum = 0;
        let maxV: number | null = null;
        let count = 0;
        for (const v of data.volume) {
            if (v === null) continue;
            totalSum += v;
            count += 1;
            if (maxV === null || v > maxV) maxV = v;
        }
        return {
            total: totalSum,
            max: maxV,
            sampleCount: count,
            intensityFills: data.volume.map((v) => intensityFill(v, maxV)),
        };
    }, [data.volume]);

    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-5">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-3">
                    Weekly Volume
                </h3>
                <span className="text-[10px] text-ink-3">hover untuk detail</span>
            </div>

            <dl className="mt-3 grid grid-cols-2 gap-3">
                <Stat label="Total" value={`${total.toFixed(1)} km`} desc={`${sampleCount} minggu`} />
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
                                backgroundColor: intensityFills,
                                hoverBackgroundColor: BAR_FILL_DARK,
                                borderColor: BAR_FILL_DARK,
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
        <div className="rounded-xl bg-line/20 p-2">
            <dt className="text-[10px] font-semibold uppercase tracking-wider text-ink-3">
                {label}
            </dt>
            <dd className="mt-0.5 text-base font-bold tabular-nums text-ink">{value}</dd>
            <dd className="text-[10px] text-ink-3">{desc}</dd>
        </div>
    );
}
