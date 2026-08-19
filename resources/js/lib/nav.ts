export type TabId = 'today' | 'collection' | 'trends' | 'plan' | 'me';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: string;
    prefixes: ReadonlyArray<string>;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    {
        id: 'today',
        label: 'Today',
        href: '/',
        icon: 'mdi:weather-sunset-up',
        prefixes: ['/', '/history', '/activities'],
    },
    {
        id: 'collection',
        label: 'Collection',
        href: '/accessories',
        icon: 'mdi:cards-outline',
        prefixes: ['/accessories'],
    },
    {
        id: 'trends',
        label: 'Trends',
        href: '/trends',
        icon: 'mdi:chart-line',
        prefixes: ['/trends'],
    },
    {
        id: 'plan',
        label: 'Plan',
        href: '/plan',
        icon: 'mdi:calendar-check-outline',
        prefixes: ['/plan', '/race'],
    },
    {
        id: 'me',
        label: 'Me',
        href: '/profile',
        icon: 'mdi:account-outline',
        prefixes: ['/profile', '/settings'],
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
