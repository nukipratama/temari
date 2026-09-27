import {
    CalendarCheck,
    ChartLine,
    RotateCcwClock,
    Sunrise,
} from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { backTargetFor, defaultTabHrefFor, ITEMS, navTabFor } from './nav';

describe('nav', () => {
    it('has 4 top-level items', () => {
        expect(ITEMS.map((item) => item.id)).toEqual([
            'today',
            'plan',
            'trends',
            'history',
        ]);
    });

    it('carries the icon component itself, so a tab ships only its own glyph', () => {
        expect(ITEMS.map((item) => item.icon)).toEqual([
            Sunrise,
            CalendarCheck,
            ChartLine,
            RotateCcwClock,
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

    describe('defaultTabHrefFor', () => {
        it('resets the calendar to the current month', () => {
            expect(
                defaultTabHrefFor(
                    'history',
                    '/history?view=calendar&month=2026-06',
                    new Date(2026, 8, 28),
                ),
            ).toBe('/history?view=calendar&month=2026-09');
        });

        it('returns the tab home route for other tab states', () => {
            expect(defaultTabHrefFor('plan', '/plan?day=2026-06-16')).toBe(
                '/plan',
            );
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

        it('uses an in-app origin for run details when one is available', () => {
            expect(
                backTargetFor('Runs/Show', {
                    href: '/plan?day=2026-06-16',
                    scrollY: 312,
                    tab: 'plan',
                }),
            ).toEqual({
                href: '/plan?day=2026-06-16',
                label: 'Plan',
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
