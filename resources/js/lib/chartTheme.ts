import type { ChartOptions } from 'chart.js';

// App is light-mode only, so Chart.js colors are constant. If dark mode is
// added later, promote this to a hook with matchMedia.
export interface ChartTheme {
    isDark: boolean;
    tick: string;
    grid: string;
    legend: string;
    tooltip: {
        backgroundColor: string;
        titleColor: string;
        bodyColor: string;
        borderColor: string;
        borderWidth: number;
    };
}

const theme: ChartTheme = {
    isDark: false,
    tick: '#3d362a',
    grid: 'rgba(31, 27, 22, 0.08)',
    legend: '#1f1b16',
    tooltip: {
        backgroundColor: '#ffffff',
        titleColor: '#1f1b16',
        bodyColor: '#1f1b16',
        borderColor: '#e5ded3',
        borderWidth: 1,
    },
};

export function useChartTheme(): ChartTheme {
    return theme;
}

export function tooltipFromTheme(theme: ChartTheme): NonNullable<NonNullable<ChartOptions['plugins']>['tooltip']> {
    return {
        enabled: true,
        backgroundColor: theme.tooltip.backgroundColor,
        titleColor: theme.tooltip.titleColor,
        bodyColor: theme.tooltip.bodyColor,
        borderColor: theme.tooltip.borderColor,
        borderWidth: theme.tooltip.borderWidth,
        padding: 10,
        titleFont: { size: 12, weight: 'bold' },
        bodyFont: { size: 12 },
        boxPadding: 6,
        usePointStyle: true,
    };
}

export function formatNumericTooltip(label: string, y: number | null, unit = ''): string {
    if (y === null) return `${label}: —`;
    const suffix = unit === '' ? '' : ` ${unit}`;
    return `${label}: ${y.toFixed(1)}${suffix}`;
}

export function kmAxisTick(value: string | number): string {
    return `${value} km`;
}
