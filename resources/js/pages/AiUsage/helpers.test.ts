import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    fmt,
    formatCost,
    formatDayLabel,
    formatDayLabelShort,
    navigate,
    presetHref,
    PRESETS,
} from './helpers';

beforeEach(() => {
    vi.mocked(router.get).mockClear();
});

describe('fmt', () => {
    it('groups thousands with a comma', () => {
        expect(fmt(1234567)).toBe('1,234,567');
    });
});

describe('formatCost', () => {
    it('renders a narrow currency symbol with two decimals', () => {
        expect(formatCost(0.05, 'USD')).toBe('$0.05');
        expect(formatCost(1234.5, 'USD')).toBe('$1,234.50');
    });

    it('follows the budget currency rather than assuming dollars', () => {
        expect(formatCost(1234.5, 'IDR')).toBe('Rp\u00a01,234.50');
    });
});

describe('navigate', () => {
    it('sends absolute from/to for a custom window', () => {
        navigate({
            range: 'custom',
            from: '2026-05-01',
            to: '2026-05-19',
            kind: null,
            origin: null,
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { from: '2026-05-01', to: '2026-05-19' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('sends the relative token instead of dates for a preset range', () => {
        navigate({
            range: '7d',
            from: '2026-05-01',
            to: '2026-05-19',
            kind: null,
            origin: null,
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('carries the kind filter when one is set', () => {
        navigate({
            range: '7d',
            from: '2026-05-01',
            to: '2026-05-19',
            kind: 'briefing',
            origin: null,
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/ai-usage',
            { range: '7d', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });
});

describe('presetHref', () => {
    it('builds a date-free href so the link stays valid tomorrow', () => {
        expect(presetHref('30d', null)).toBe('/devtools/ai-usage?range=30d');
    });

    it('preserves the active kind filter', () => {
        expect(presetHref('7d', 'briefing')).toBe(
            '/devtools/ai-usage?range=7d&kind=briefing',
        );
    });
});

describe('PRESETS', () => {
    it('offers the five relative windows in shortest-first order', () => {
        expect(PRESETS.map((p) => p.token)).toEqual([
            'today',
            '7d',
            '30d',
            'month',
            'all',
        ]);
    });
});

describe('day labels', () => {
    it('formats a day key as day + short month', () => {
        expect(formatDayLabel('2026-05-18')).toBe('may 18');
    });

    it('formats a day key as short weekday + day for the dense axis', () => {
        expect(formatDayLabelShort('2026-05-18')).toBe('18 mon');
    });
});
