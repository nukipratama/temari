import { describe, expect, it } from 'vitest';

import { formatCost, formatCount, formatDay, formatTimestamp } from './format';

describe('formatCost', () => {
    it('renders dollars with two decimals', () => {
        expect(formatCost(1.5, 'USD')).toBe('$1.50');
    });

    it('keeps four decimals for a sub-cent amount, so it never reads as free', () => {
        expect(formatCost(0.0012, 'USD')).toBe('$0.0012');
    });

    it('renders an exact zero plainly', () => {
        expect(formatCost(0, 'USD')).toBe('$0.00');
    });
});

describe('formatCount', () => {
    it('groups thousands', () => {
        expect(formatCount(12345)).toBe('12,345');
    });
});

describe('formatDay', () => {
    it('reads a Y-m-d day without crossing a timezone', () => {
        expect(formatDay('2026-09-10')).toBe('Sep 10');
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
