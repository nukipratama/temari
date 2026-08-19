import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
    type Chart,
    type Plugin,
} from 'chart.js';

import { PEWTER, SERIES } from '@/lib/palette';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Filler,
    Tooltip,
    Legend,
);

ChartJS.defaults.font.family =
    "'Plus Jakarta Sans Variable', ui-sans-serif, system-ui, sans-serif";
ChartJS.defaults.font.size = 12;
ChartJS.defaults.color = PEWTER.ink2;

Object.assign(ChartJS.defaults.plugins.tooltip, {
    backgroundColor: PEWTER.sky,
    titleColor: PEWTER.cream,
    bodyColor: PEWTER.inkOnSky,
    borderColor: PEWTER.sky2,
    borderWidth: 1,
    cornerRadius: 11,
    padding: 12,
    displayColors: true,
    boxWidth: 8,
    boxHeight: 8,
    boxPadding: 6,
    usePointStyle: true,
    titleFont: { weight: 600 as const },
});

/** A line/area chart is read across the x axis, so the hover layer is a crosshair. */
export const crosshair: Plugin = {
    id: 'crosshair',
    afterDatasetsDraw(chart: Chart) {
        const active = chart.getActiveElements();
        if (active.length === 0) return;
        const { ctx, chartArea } = chart;
        const x = active[0].element.x;
        ctx.save();
        ctx.strokeStyle = `${PEWTER.ink3}66`;
        ctx.lineWidth = 1;
        ctx.setLineDash([3, 3]);
        ctx.beginPath();
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

/** Shared axis styling, so every chart on the page reads as one system. */
export function scales(y: Record<string, unknown> = {}) {
    return {
        x: {
            grid: { display: false },
            border: { color: SERIES.grid },
            ticks: {
                color: PEWTER.ink3,
                maxRotation: 0,
                autoSkipPadding: 24,
            },
        },
        y: {
            grid: { color: SERIES.grid },
            border: { display: false },
            ticks: { color: PEWTER.ink3, padding: 8, maxTicksLimit: 5 },
            ...y,
        },
    };
}

export const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    animation: { duration: 700, easing: 'easeOutQuart' as const },
};
