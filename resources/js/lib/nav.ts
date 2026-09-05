export type TabId = 'today' | 'plan' | 'trends' | 'history';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: string; // lucide-react component name, per decision 16 — not an iconify string
}

export interface BackTarget {
    href: string;
    label: string;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    {
        id: 'today',
        label: 'Today',
        href: '/',
        icon: 'Sunrise',
    },
    {
        id: 'plan',
        label: 'Plan',
        href: '/plan',
        icon: 'CalendarCheck',
    },
    {
        id: 'trends',
        label: 'Trends',
        href: '/trends',
        icon: 'LineChart',
    },
    {
        id: 'history',
        label: 'History',
        href: '/history',
        icon: 'History',
    },
];

// Keyed by Inertia page component rather than URL prefix: Race is a sub-page of
// Plan and lights the plan tab, which a path prefix cannot express without
// also claiming every other /race-adjacent route.
const NAV_SCREENS: Readonly<Record<string, TabId>> = {
    Home: 'today',
    Plan: 'plan',
    Race: 'plan',
    Trends: 'trends',
    History: 'history',
};

const TODAY: BackTarget = { href: '/', label: 'Today' };

const BACK_TARGETS: Readonly<Record<string, BackTarget>> = {
    'Runs/Show': { href: '/history', label: 'History' },
    Inbox: TODAY,
    Profile: TODAY,
    'Settings/Index': { href: '/profile', label: 'Profile' },
};

/** The bottom-nav tab a page lights, or null when it is a pushed screen. */
export function navTabFor(component: string): TabId | null {
    return NAV_SCREENS[component] ?? null;
}

/**
 * Where a pushed screen's back chevron goes, or null on a bottom-nav screen.
 * A fixed parent, not `history.back()`: a deep link from a notification or a
 * shared URL opens these cold with nothing behind them.
 */
export function backTargetFor(component: string): BackTarget | null {
    if (navTabFor(component) !== null) {
        return null;
    }

    return BACK_TARGETS[component] ?? TODAY;
}
