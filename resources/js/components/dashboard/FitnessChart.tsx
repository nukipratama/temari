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
} from 'chart.js';
import type { FitnessChartData } from '@/types/inertia';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

interface FitnessChartProps {
    data: FitnessChartData;
}

export default function FitnessChart({ data }: Readonly<FitnessChartProps>) {
    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-5 dark:border-line-dark dark:bg-surface-dark-elev">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                Fitness &amp; Form
            </h3>
            <div className="relative mt-4 h-56">
                <Line
                    data={{
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'CTL',
                                data: data.ctl,
                                borderColor: '#2e7d5c',
                                backgroundColor: 'rgba(46,125,92,0.15)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 0,
                                borderWidth: 2,
                            },
                            {
                                label: 'ATL',
                                data: data.atl,
                                borderColor: '#f4a93b',
                                tension: 0.4,
                                pointRadius: 0,
                                borderWidth: 2,
                            },
                            {
                                label: 'Form',
                                data: data.form,
                                borderColor: '#6e8aaf',
                                borderDash: [4, 4],
                                tension: 0.4,
                                pointRadius: 0,
                                borderWidth: 1.5,
                            },
                        ],
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } },
                            },
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
