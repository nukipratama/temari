import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    athleteLabel,
    athletePath,
    FLAGGED_REASONS,
    fmt,
    formatCost,
    formatDayLabel,
    formatDayLabelShort,
    formatTimestamp,
    median,
    navigate,
    PAUSE_LABEL,
    plural,
    presetHref,
    PRESETS,
    REASON_LABEL,
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

    it('keeps four decimals for a sub-cent amount, so it never reads as free', () => {
        expect(formatCost(0.0012, 'USD')).toBe('$0.0012');
    });

    it('renders an exact zero plainly', () => {
        expect(formatCost(0, 'USD')).toBe('$0.00');
    });
});

describe('formatTimestamp', () => {
    it('renders a placeholder for a missing timestamp', () => {
        expect(formatTimestamp(null)).toBe('—');
    });

    it('renders a date and time for an ISO string', () => {
        expect(formatTimestamp('2026-09-10T08:30:00Z')).toContain('Sep 10');
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

describe('plural', () => {
    it('keeps the noun singular for exactly one', () => {
        expect(plural(1, 'athlete')).toBe('1 athlete');
    });

    it('adds an s for zero and for more than one', () => {
        expect(plural(0, 'athlete')).toBe('0 athletes');
        expect(plural(2, 'athlete')).toBe('2 athletes');
    });
});

describe('median', () => {
    it('returns 0 for an empty series', () => {
        expect(median([])).toBe(0);
    });

    it('returns the middle value of an odd-length series', () => {
        expect(median([3, 1, 2])).toBe(2);
    });

    it('averages the two middle values of an even-length series', () => {
        expect(median([1, 4, 2, 3])).toBe(2.5);
    });
});

describe('reason and pause labels', () => {
    it('labels every rule-based reason', () => {
        expect(Object.keys(REASON_LABEL)).toEqual([
            'demo',
            'capped',
            'return',
            'dead_letter',
            'content_filter',
            'unattributed',
        ]);
    });

    it('flags only the reasons that want explaining', () => {
        expect(FLAGGED_REASONS.has('content_filter')).toBe(true);
        expect(FLAGGED_REASONS.has('dead_letter')).toBe(true);
        expect(FLAGGED_REASONS.has('unattributed')).toBe(true);
        expect(FLAGGED_REASONS.has('demo')).toBe(false);
        expect(FLAGGED_REASONS.has('capped')).toBe(false);
        expect(FLAGGED_REASONS.has('return')).toBe(false);
    });

    it('translates a known pause reason', () => {
        expect(PAUSE_LABEL.kill_switch).toBe('kill switch off');
    });
});
