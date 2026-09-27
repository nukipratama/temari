import {
    CalendarCheck,
    ChartLine,
    RotateCcwClock,
    Sunrise,
} from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';

import { navTabFor } from '@/lib/navRoutes';

import type { TabId } from '@/lib/navRoutes';

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
