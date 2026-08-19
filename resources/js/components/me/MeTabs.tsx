import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type MeTab = 'profile' | 'settings' | 'accessories';

interface MeTabsProps {
    active: MeTab;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<MeTab>> = [
    {
        id: 'profile',
        label: 'Profile',
        href: '/profile',
        icon: 'mdi:account-outline',
    },
    {
        id: 'settings',
        label: 'Settings',
        href: '/settings',
        icon: 'mdi:cog-outline',
    },
    {
        id: 'accessories',
        label: 'Accessories',
        href: '/accessories',
        icon: 'mdi:tshirt-crew-outline',
    },
];

export default function MeTabs({ active, className }: Readonly<MeTabsProps>) {
    return <SectionTabs tabs={TABS} active={active} className={className} />;
}
