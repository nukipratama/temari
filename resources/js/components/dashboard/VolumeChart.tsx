import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';
import type { FitnessChartData } from '@/types/inertia';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface VolumeChartProps {
    data: FitnessChartData;
}

export default function VolumeChart({ data }: Readonly<VolumeChartProps>) {
    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-5 dark:border-line-dark dark:bg-surface-dark-elev">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                Weekly Volume
            </h3>
            <div className="relative mt-4 h-56">
                <Bar
                    data={{
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'km',
                                data: data.volume,
                                backgroundColor: 'rgba(217,160,60,0.35)',
                                borderColor: '#d9a03c',
                                borderWidth: 1,
                                borderRadius: 4,
                            },
                        ],
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            x: { ticks: { font: { size: 10 } } },
                            y: { ticks: { font: { size: 10 } } },
                        },
                    }}
                />
            </div>
        </div>
    );
}
