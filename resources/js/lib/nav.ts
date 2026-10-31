import {
    CalendarCheck,
    ChartLine,
    RotateCcwClock,
    Sunrise,
} from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';
import type { TabId } from '@/lib/navRoutes';

import { navTabFor, RACE_PATH } from '@/lib/navRoutes';

export { navTabFor } from '@/lib/navRoutes';
export type { TabId } from '@/lib/navRoutes';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: IconComponent;
}

export interface BackTarget {
    href: string;
    label: string;
}

export interface ContextualOrigin {
    href: string;
    scrollY: number;
    tab: TabId;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    {
        id: 'today',
        label: 'Today',
        href: '/',
        icon: Sunrise,
    },
    {
        id: 'plan',
        label: 'Plan',
        href: '/plan',
        icon: CalendarCheck,
    },
    {
        id: 'trends',
        label: 'Trends',
        href: '/trends',
        icon: ChartLine,
    },
    {
        id: 'history',
        label: 'History',
        href: '/history',
        icon: RotateCcwClock,
    },
];

const TODAY: BackTarget = { href: '/', label: 'Today' };

const BACK_TARGETS: Readonly<Record<string, BackTarget>> = {
    'Runs/Show': { href: '/history', label: 'History' },
    Inbox: TODAY,
    Profile: TODAY,
    'Settings/Index': { href: '/profile', label: 'Profile' },
};

export type NavDirection = 'push' | 'pop';

const SCREEN_PATHS: Readonly<Record<string, string>> = {
    '/': 'Home',
    '/plan': 'Plan',
    [RACE_PATH]: 'Race',
    '/trends': 'Trends',
    '/history': 'History',
    '/inbox': 'Inbox',
    '/profile': 'Profile',
    '/settings': 'Settings/Index',
};

/** The page component a pathname renders, for the tab and pushed screens only. */
export function screenForPath(pathname: string): string | null {
    if (/^\/activities\/\d+$/.test(pathname)) {
        return 'Runs/Show';
    }

    return SCREEN_PATHS[pathname] ?? null;
}

/** Whether `child` is a pushed screen opened from `parent`; a run opens from any tab. */
function isParentOf(parent: string, child: string): boolean {
    if (child === 'Runs/Show') {
        return navTabFor(parent) !== null;
    }

    const target = BACK_TARGETS[child];

    return target !== undefined && screenForPath(target.href) === parent;
}

/** Whether moving from `from` to `to` pushes a screen, pops back to its parent, or neither. */
export function navDirection(
    from: string,
    to: string | null,
): NavDirection | null {
    if (to === null) {
        return null;
    }

    if (isParentOf(from, to)) {
        return 'push';
    }

    return isParentOf(to, from) ? 'pop' : null;
}

/** The default tab destination, resetting a calendar to the month of the server's `today`. */
export function defaultTabHrefFor(
    tab: TabId,
    currentHref: string,
    today: string,
): string {
    const query = currentHref.split('?')[1]?.split('#')[0] ?? '';
    if (
        tab === 'history' &&
        new URLSearchParams(query).get('view') === 'calendar'
    ) {
        return `/history?view=calendar&month=${today.slice(0, 7)}`;
    }

    return ITEMS.find((item) => item.id === tab)?.href ?? '/';
}

/**
 * Where a pushed screen's back chevron goes, or null on a bottom-nav screen.
 * A fixed parent, not `history.back()`: a deep link from a notification or a
 * shared URL opens these cold with nothing behind them.
 */
export function backTargetFor(
    component: string,
    origin: ContextualOrigin | null = null,
): BackTarget | null {
    if (navTabFor(component) !== null) {
        return null;
    }

    if (component === 'Runs/Show' && origin !== null) {
        const item = ITEMS.find((candidate) => candidate.id === origin.tab);
        if (item !== undefined) {
            return {
                href: origin.href,
                label: item.label,
            };
        }
    }

    return BACK_TARGETS[component] ?? TODAY;
}
