import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type TodayHistoryTab = 'today' | 'history';

interface TodayHistoryTabsProps {
    active: TodayHistoryTab;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<TodayHistoryTab>> = [
    { id: 'today', label: 'Today', href: '/', icon: 'mdi:weather-sunset-up' },
    {
        id: 'history',
        label: 'History',
        href: '/activities',
        icon: 'mdi:history',
    },
];

export default function TodayHistoryTabs({
    active,
    className,
}: Readonly<TodayHistoryTabsProps>) {
    return <SectionTabs tabs={TABS} active={active} className={className} />;
}
