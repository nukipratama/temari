import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type PlanRaceTab = 'plan' | 'race';

interface PlanRaceTabsProps {
    active: PlanRaceTab;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<PlanRaceTab>> = [
    {
        id: 'plan',
        label: 'Schedule',
        href: '/plan',
        icon: 'mdi:calendar-check-outline',
    },
    {
        id: 'race',
        label: 'Race Goal',
        href: '/race',
        icon: 'mdi:flag-checkered',
    },
];

export default function PlanRaceTabs({
    active,
    className,
}: Readonly<PlanRaceTabsProps>) {
    return <SectionTabs tabs={TABS} active={active} className={className} />;
}
