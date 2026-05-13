import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatNumericTooltip, kmAxisTick, tooltipFromTheme, useChartTheme } from './chartTheme';

afterEach(() => {
    vi.unstubAllGlobals();
});

function mockMatchMedia(matches: boolean) {
    const fn = vi.fn(() => ({
        matches,
        addEventListener: () => {},
        removeEventListener: () => {},
    }));
    Object.defineProperty(globalThis, 'matchMedia', { configurable: true, writable: true, value: fn });
}

describe('useChartTheme', () => {
    it('returns light theme when prefers-color-scheme is light', () => {
        mockMatchMedia(false);
        const { result } = renderHook(() => useChartTheme());
        expect(result.current.isDark).toBe(false);
        expect(result.current.tick).toBe('#6f6358');
    });

    it('returns dark theme when prefers-color-scheme is dark', () => {
        mockMatchMedia(true);
        const { result } = renderHook(() => useChartTheme());
        expect(result.current.isDark).toBe(true);
        expect(result.current.tick).toBe('#d0c6b5');
    });

    it('falls back to light when matchMedia is absent (SSR)', () => {
        // @ts-expect-error stubbing for SSR-like env
        delete globalThis.matchMedia;
        const { result } = renderHook(() => useChartTheme());
        expect(result.current.isDark).toBe(false);
    });
});

describe('tooltipFromTheme', () => {
    it('forwards theme colors into a Chart.js tooltip config', () => {
        mockMatchMedia(false);
        const { result } = renderHook(() => useChartTheme());
        const tip = tooltipFromTheme(result.current);
        expect(tip.backgroundColor).toBe(result.current.tooltip.backgroundColor);
        expect(tip.bodyColor).toBe(result.current.tooltip.bodyColor);
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
