import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    athleteLabel,
    athletePath,
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
            '/devtools/narration',
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
            '/devtools/narration',
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
            '/devtools/narration',
            { range: '7d', kind: 'briefing' },
            { preserveState: true, preserveScroll: true },
        );
    });
});

describe('navigate, athlete filter', () => {
    it('carries the athlete the chart is narrowed to', () => {
        navigate({
            range: '7d',
            from: '2026-05-01',
            to: '2026-05-19',
            kind: null,
            origin: null,
            athlete: 7,
        });

        expect(router.get).toHaveBeenCalledWith(
            '/devtools/narration',
            { range: '7d', athlete: '7' },
            { preserveState: true, preserveScroll: true },
        );
    });
});

describe('athletePath', () => {
    it('points at the per-athlete page under the overview', () => {
        expect(athletePath(12)).toBe('/devtools/narration/athletes/12');
    });
});

describe('athleteLabel', () => {
    it('uses the name when there is one', () => {
        expect(athleteLabel('Nuki', 3)).toBe('Nuki');
    });

    it('falls back to the bare id for a deleted account', () => {
        expect(athleteLabel(null, 3)).toBe('User #3');
    });
});

describe('presetHref', () => {
    it('builds a date-free href so the link stays valid tomorrow', () => {
        expect(presetHref('30d', null)).toBe('/devtools/narration?range=30d');
    });

    it('preserves the active kind filter', () => {
        expect(presetHref('7d', 'briefing')).toBe(
            '/devtools/narration?range=7d&kind=briefing',
        );
    });

    it('preserves the athlete filter', () => {
        expect(presetHref('7d', null, null, 4)).toBe(
            '/devtools/narration?range=7d&athlete=4',
        );
    });
});

describe('PRESETS', () => {
    it('labels every window in lowercase chrome', () => {
        expect(PRESETS.map((p) => p.label)).toEqual([
            'today',
            '7 days',
            '30 days',
            'this month',
            'all',
        ]);
    });

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
