export type TabId = 'today' | 'plan' | 'trends' | 'history';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: string; // lucide-react component name, per decision 16 — not an iconify string
    prefixes: ReadonlyArray<string>;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    {
        id: 'today',
        label: 'Today',
        href: '/',
        icon: 'Sunrise',
        prefixes: ['/'],
    },
    {
        id: 'plan',
        label: 'Plan',
        href: '/plan',
        icon: 'CalendarCheck',
        prefixes: ['/plan', '/race'],
    },
    {
        id: 'trends',
        label: 'Trends',
        href: '/trends',
        icon: 'LineChart',
        prefixes: ['/trends'],
    },
    {
        id: 'history',
        label: 'History',
        href: '/history',
        icon: 'History',
        prefixes: ['/history', '/activities'],
    },
];

export function activeTabFromUrl(url: string): TabId | null {
    const path = url.split('?')[0];
    return (
        ITEMS.find((item) =>
            item.prefixes.some((prefix) =>
                prefix === '/'
                    ? path === '/'
                    : path === prefix || path.startsWith(`${prefix}/`),
            ),
        )?.id ?? null
    );
}
