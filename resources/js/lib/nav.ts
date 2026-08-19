export type TabId = 'today' | 'trends' | 'history';

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
        prefixes: ['/', '/plan', '/race'],
    },
    {
        id: 'trends',
        label: 'Trends',
        href: '/trends',
        icon: 'mdi:chart-line',
        prefixes: ['/trends'],
    },
    {
        id: 'history',
        label: 'History',
        href: '/history',
        icon: 'mdi:history',
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
