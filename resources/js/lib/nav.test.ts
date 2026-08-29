import { describe, expect, it } from 'vitest';

import { activeTabFromUrl, ITEMS } from './nav';

describe('nav', () => {
    it('has 4 top-level items', () => {
        expect(ITEMS.map((item) => item.id)).toEqual([
            'today',
            'plan',
            'trends',
            'history',
        ]);
    });

    it('carries a lucide component name, not an iconify string, per decision 16', () => {
        expect(ITEMS.map((item) => item.icon)).toEqual([
            'Sunrise',
            'CalendarCheck',
            'LineChart',
            'History',
        ]);
    });

    it('resolves /trends to the Trends tab', () => {
        expect(activeTabFromUrl('/trends')).toBe('trends');
    });

    it('resolves the root path to Today', () => {
        expect(activeTabFromUrl('/')).toBe('today');
    });

    it('resolves History to its own tab', () => {
        expect(activeTabFromUrl('/history')).toBe('history');
        expect(activeTabFromUrl('/activities/123')).toBe('history');
    });

    it('resolves Plan and Race to their own tab, split out of Today', () => {
        expect(activeTabFromUrl('/plan')).toBe('plan');
        expect(activeTabFromUrl('/race')).toBe('plan');
    });

    it('resolves no bottom-nav tab for Profile, Settings, or Accessories — reached via the avatar, not a tab', () => {
        expect(activeTabFromUrl('/profile')).toBeNull();
        expect(activeTabFromUrl('/settings')).toBeNull();
        expect(activeTabFromUrl('/accessories')).toBeNull();
    });

    it('ignores a query string when matching', () => {
        expect(activeTabFromUrl('/plan?tab=race')).toBe('plan');
    });

    it('returns null for a path that matches no prefix', () => {
        expect(activeTabFromUrl('/xyz')).toBeNull();
    });

    it('does not treat every path as Today just because "/" is a prefix', () => {
        expect(activeTabFromUrl('/accessories')).not.toBe('today');
    });

    it('does not fold Plan or Race under Today anymore', () => {
        expect(activeTabFromUrl('/plan')).not.toBe('today');
        expect(activeTabFromUrl('/race')).not.toBe('today');
    });
});
