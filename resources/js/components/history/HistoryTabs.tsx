import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type HistoryTab = 'feed' | 'calendar';

interface HistoryTabsProps {
    active: HistoryTab;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<HistoryTab>> = [
    {
        id: 'feed',
        label: 'Feed',
        href: '/activities',
        icon: 'mdi:shoe-print',
    },
    {
        id: 'calendar',
        label: 'Calendar',
        href: '/calendar',
        icon: 'mdi:calendar-blank-outline',
    },
];

export default function HistoryTabs({
    active,
    className,
}: Readonly<HistoryTabsProps>) {
    return <SectionTabs tabs={TABS} active={active} className={className} />;
}
