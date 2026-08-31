import { describe, expect, it } from 'vitest';

import { backTargetFor, ITEMS, navTabFor } from './nav';

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

    describe('navTabFor', () => {
        it('lights a tab for each of the five bottom-nav screens', () => {
            expect(navTabFor('Home')).toBe('today');
            expect(navTabFor('Plan')).toBe('plan');
            expect(navTabFor('Trends')).toBe('trends');
            expect(navTabFor('History')).toBe('history');
        });

        it('lights the plan tab on Race, which is a sub-page of Plan', () => {
            expect(navTabFor('Race')).toBe('plan');
        });

        it('lights no tab on a pushed screen', () => {
            expect(navTabFor('Runs/Show')).toBeNull();
            expect(navTabFor('Inbox')).toBeNull();
            expect(navTabFor('Profile')).toBeNull();
            expect(navTabFor('Settings/Index')).toBeNull();
        });
    });

    describe('backTargetFor', () => {
        it('gives no back target to a bottom-nav screen', () => {
            expect(backTargetFor('Home')).toBeNull();
            expect(backTargetFor('Race')).toBeNull();
        });

        it('sends each pushed screen to its fixed parent', () => {
            expect(backTargetFor('Runs/Show')).toEqual({
                href: '/history',
                label: 'History',
            });
            expect(backTargetFor('Inbox')).toEqual({
                href: '/',
                label: 'Today',
            });
            expect(backTargetFor('Profile')).toEqual({
                href: '/',
                label: 'Today',
            });
            expect(backTargetFor('Settings/Index')).toEqual({
                href: '/profile',
                label: 'Profile',
            });
        });

        it('defaults an unlisted routed screen to pushed chrome back to Today', () => {
            expect(backTargetFor('Collection/Accessories')).toEqual({
                href: '/',
                label: 'Today',
            });
        });
    });
});
