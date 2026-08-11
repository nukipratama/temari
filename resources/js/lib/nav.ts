export type TabId = 'hari-ini' | 'koleksi' | 'plan' | 'aku';

export interface NavItem {
    id: TabId;
    label: string;
    href: string;
    icon: string;
    prefixes: ReadonlyArray<string>;
}

export const ITEMS: ReadonlyArray<NavItem> = [
    {
        id: 'hari-ini',
        label: 'Today',
        href: '/',
        icon: 'mdi:weather-sunset-up',
        prefixes: ['/', '/activities', '/calendar'],
    },
    {
        id: 'koleksi',
        label: 'Collection',
        href: '/cards',
        icon: 'mdi:cards-outline',
        prefixes: ['/cards', '/accessories', '/records', '/badges'],
    },
    {
        id: 'plan',
        label: 'Plan',
        href: '/plan',
        icon: 'mdi:calendar-check-outline',
        prefixes: ['/plan', '/race'],
    },
    {
        id: 'aku',
        label: 'Me',
        href: '/profile',
        icon: 'mdi:account-outline',
        prefixes: ['/profile', '/settings'],
    },
];

export function activeTabFromUrl(url: string): TabId | null {
    const path = url.split('?')[0];
    for (const item of ITEMS) {
        const matches = item.prefixes.some((prefix) =>
            prefix === '/'
                ? path === '/'
                : path === prefix || path.startsWith(`${prefix}/`),
        );
        if (matches) return item.id;
    }
    return null;
}
