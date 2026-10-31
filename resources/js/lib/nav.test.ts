import {
    CalendarCheck,
    ChartLine,
    RotateCcwClock,
    Sunrise,
} from 'lucide-react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    backTargetFor,
    defaultTabHrefFor,
    isPushedScreen,
    ITEMS,
    navDirection,
    navTabFor,
    screenForPath,
} from './nav';

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
        afterEach(() => {
            vi.useRealTimers();
        });

        it("resets the calendar to the server date's month", () => {
            expect(
                defaultTabHrefFor(
                    'history',
                    '/history?view=calendar&month=2026-06',
                    '2026-09-28',
                ),
            ).toBe('/history?view=calendar&month=2026-09');
        });

        it('follows the server month when the device clock is already in the next one', () => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date(2026, 9, 1, 0, 30));

            expect(
                defaultTabHrefFor(
                    'history',
                    '/history?view=calendar&month=2026-06',
                    '2026-09-30',
                ),
            ).toBe('/history?view=calendar&month=2026-09');
        });

        it('returns the tab home route for other tab states', () => {
            expect(
                defaultTabHrefFor('plan', '/plan?day=2026-06-16', '2026-06-16'),
            ).toBe('/plan');
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

    describe('screenForPath', () => {
        it.each([
            ['/', 'Home'],
            ['/plan', 'Plan'],
            ['/race', 'Race'],
            ['/trends', 'Trends'],
            ['/history', 'History'],
            ['/activities/42', 'Runs/Show'],
            ['/inbox', 'Inbox'],
            ['/profile', 'Profile'],
            ['/settings', 'Settings/Index'],
        ])('maps %s to %s', (path, component) => {
            expect(screenForPath(path)).toBe(component);
        });

        it.each(['/activities/42/effort', '/activities/x', '/login'])(
            'knows nothing of %s',
            (path) => {
                expect(screenForPath(path)).toBeNull();
            },
        );
    });

    describe('navDirection', () => {
        it.each([
            ['History', 'Runs/Show'],
            ['Home', 'Inbox'],
            ['Plan', 'Inbox'],
            ['Trends', 'Profile'],
            ['History', 'Settings/Index'],
            ['Profile', 'Settings/Index'],
            ['Inbox', 'Runs/Show'],
            ['Runs/Show', 'Runs/Show'],
            ['Profile', 'Inbox'],
        ])('pushes from %s into %s', (from, to) => {
            expect(navDirection(from, to)).toBe('push');
        });

        it.each([
            ['Runs/Show', 'History'],
            ['Inbox', 'Home'],
            ['Profile', 'Home'],
            ['Settings/Index', 'Profile'],
        ])('pops from %s back to its back target %s', (from, to) => {
            expect(navDirection(from, to)).toBe('pop');
        });

        it('pops a run back to the tab it was opened from, not History', () => {
            const origin = {
                href: '/plan?day=2026-10-12',
                scrollY: 0,
                tab: 'plan' as const,
            };

            expect(navDirection('Runs/Show', 'Plan', origin)).toBe('pop');
            expect(navDirection('Runs/Show', 'History', origin)).toBeNull();
        });

        it.each([
            ['Home', 'Plan'],
            ['Inbox', 'Plan'],
            ['Runs/Show', 'Home'],
            ['Settings/Index', 'Home'],
        ])('keeps the fade from %s to %s', (from, to) => {
            expect(navDirection(from, to)).toBeNull();
        });

        it('has no direction to an unknown screen', () => {
            expect(navDirection('History', null)).toBeNull();
        });
    });

    it.each([
        ['Runs/Show', true],
        ['Inbox', true],
        ['Profile', true],
        ['Settings/Index', true],
        ['Home', false],
        ['Race', false],
    ])('counts %s as pushed: %s', (component, pushed) => {
        expect(isPushedScreen(component)).toBe(pushed);
    });
});
