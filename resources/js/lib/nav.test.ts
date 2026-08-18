import { describe, expect, it } from 'vitest';

import { activeTabFromUrl, ITEMS } from './nav';

describe('nav', () => {
    it('has 5 top-level items', () => {
        expect(ITEMS.map((item) => item.id)).toEqual([
            'today',
            'collection',
            'trends',
            'plan',
            'me',
        ]);
    });

    it('resolves /trends to the Trends tab', () => {
        expect(activeTabFromUrl('/trends')).toBe('trends');
    });

    it('resolves the root path to Today', () => {
        expect(activeTabFromUrl('/')).toBe('today');
    });

    it('folds History under Today', () => {
        expect(activeTabFromUrl('/activities')).toBe('today');
        expect(activeTabFromUrl('/activities/123')).toBe('today');
        expect(activeTabFromUrl('/calendar')).toBe('today');
    });

    it('folds Race under Plan', () => {
        expect(activeTabFromUrl('/plan')).toBe('plan');
        expect(activeTabFromUrl('/race')).toBe('plan');
    });

    it('resolves the Collection sub-pages, including /badges', () => {
        expect(activeTabFromUrl('/cards')).toBe('collection');
        expect(activeTabFromUrl('/accessories')).toBe('collection');
        expect(activeTabFromUrl('/records')).toBe('collection');
        expect(activeTabFromUrl('/badges')).toBe('collection');
    });

    it('no longer folds Race under Me', () => {
        expect(activeTabFromUrl('/profile')).toBe('me');
        expect(activeTabFromUrl('/settings')).toBe('me');
        expect(activeTabFromUrl('/race')).not.toBe('me');
    });

    it('ignores a query string when matching', () => {
        expect(activeTabFromUrl('/plan?tab=race')).toBe('plan');
    });

    it('returns null for a path that matches no prefix', () => {
        expect(activeTabFromUrl('/xyz')).toBeNull();
    });

    it('does not treat every path as Today just because "/" is a prefix', () => {
        expect(activeTabFromUrl('/cards')).not.toBe('today');
    });
});
