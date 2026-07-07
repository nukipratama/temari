import { describe, expect, it } from 'vitest';
import { formatNumericTooltip, kmAxisTick, tooltipFromTheme, useChartTheme } from './chartTheme';

describe('useChartTheme', () => {
    it('returns the light theme (app is light-mode only)', () => {
        const theme = useChartTheme();
        expect(theme.isDark).toBe(false);
        expect(theme.tick).toBe('#3d362a');
    });
});

describe('tooltipFromTheme', () => {
    it('forwards theme colors into a Chart.js tooltip config', () => {
        const theme = useChartTheme();
        const tip = tooltipFromTheme(theme);
        expect(tip.backgroundColor).toBe(theme.tooltip.backgroundColor);
        expect(tip.bodyColor).toBe(theme.tooltip.bodyColor);
        expect(tip.enabled).toBe(true);
    });
});

describe('formatNumericTooltip', () => {
    it('formats with one decimal + no unit by default', () => {
        expect(formatNumericTooltip('CTL', 42.345)).toBe('CTL: 42.3');
    });
    it('appends a unit suffix when provided', () => {
        expect(formatNumericTooltip('Volume', 7.5, 'km')).toBe('Volume: 7.5 km');
    });
    it('renders an em-dash when y is null', () => {
        expect(formatNumericTooltip('Form', null)).toBe('Form: —');
    });
});

describe('kmAxisTick', () => {
    it('appends "km" to numeric ticks', () => {
        expect(kmAxisTick(15)).toBe('15 km');
        expect(kmAxisTick('20')).toBe('20 km');
    });
});
