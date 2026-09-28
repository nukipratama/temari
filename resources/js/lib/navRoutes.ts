export type TabId = 'today' | 'plan' | 'trends' | 'history';

export const TAB_IDS: readonly TabId[] = ['today', 'plan', 'trends', 'history'];

const NAV_SCREENS: Readonly<Record<string, TabId>> = {
    Home: 'today',
    Plan: 'plan',
    Race: 'plan',
    Trends: 'trends',
    History: 'history',
};

export function isTabId(value: unknown): value is TabId {
    return typeof value === 'string' && TAB_IDS.includes(value as TabId);
}

export function navTabFor(component: string): TabId | null {
    return NAV_SCREENS[component] ?? null;
}
