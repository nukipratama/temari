import { describe, expect, it } from 'vitest';

import { activeTabFromUrl, ITEMS } from './nav';

describe('nav', () => {
    it('has 4 top-level items', () => {
        expect(ITEMS.map((item) => item.id)).toEqual([
            'hari-ini',
            'koleksi',
            'plan',
            'aku',
        ]);
    });

    it('resolves the root path to Today', () => {
        expect(activeTabFromUrl('/')).toBe('hari-ini');
    });

    it('folds History under Today', () => {
        expect(activeTabFromUrl('/activities')).toBe('hari-ini');
        expect(activeTabFromUrl('/activities/123')).toBe('hari-ini');
        expect(activeTabFromUrl('/calendar')).toBe('hari-ini');
    });

    it('folds Race under Plan', () => {
        expect(activeTabFromUrl('/plan')).toBe('plan');
        expect(activeTabFromUrl('/race')).toBe('plan');
    });

    it('resolves the Collection sub-pages, including /badges', () => {
        expect(activeTabFromUrl('/cards')).toBe('koleksi');
        expect(activeTabFromUrl('/accessories')).toBe('koleksi');
        expect(activeTabFromUrl('/records')).toBe('koleksi');
        expect(activeTabFromUrl('/badges')).toBe('koleksi');
    });

    it('no longer folds Race under Me', () => {
        expect(activeTabFromUrl('/profile')).toBe('aku');
        expect(activeTabFromUrl('/settings')).toBe('aku');
        expect(activeTabFromUrl('/race')).not.toBe('aku');
    });

    it('ignores a query string when matching', () => {
        expect(activeTabFromUrl('/plan?tab=race')).toBe('plan');
    });

    it('returns null for a path that matches no prefix', () => {
        expect(activeTabFromUrl('/xyz')).toBeNull();
    });

    it('does not treat every path as Today just because "/" is a prefix', () => {
        expect(activeTabFromUrl('/cards')).not.toBe('hari-ini');
    });
});
