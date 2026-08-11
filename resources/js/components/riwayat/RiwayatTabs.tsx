import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type RiwayatTab = 'jejak' | 'kalender';

interface RiwayatTabsProps {
    active: RiwayatTab;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<RiwayatTab>> = [
    {
        id: 'jejak',
        label: 'Feed',
        href: '/activities',
        icon: 'mdi:shoe-print',
    },
    {
        id: 'kalender',
        label: 'Calendar',
        href: '/calendar',
        icon: 'mdi:calendar-blank-outline',
    },
];

export default function RiwayatTabs({
    active,
    className,
}: Readonly<RiwayatTabsProps>) {
    return <SectionTabs tabs={TABS} active={active} className={className} />;
}
