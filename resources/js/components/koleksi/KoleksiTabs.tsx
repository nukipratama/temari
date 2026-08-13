import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type KoleksiTab = 'cards' | 'records' | 'accessories' | 'badges';

interface KoleksiTabsProps {
    active: KoleksiTab;
    /** Active tab's count chip only — sibling counts would need extra queries per page load. */
    activeCount?: string;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<KoleksiTab>> = [
    { id: 'cards', label: 'Cards', href: '/cards', icon: 'mdi:cards-outline' },
    {
        id: 'records',
        label: 'Records',
        href: '/records',
        icon: 'mdi:trophy-outline',
    },
    {
        id: 'accessories',
        label: 'Accessories',
        href: '/accessories',
        icon: 'mdi:tshirt-crew-outline',
    },
    {
        id: 'badges',
        label: 'Badges',
        href: '/badges',
        icon: 'mdi:seal-variant',
    },
];

export default function KoleksiTabs({
    active,
    activeCount,
    className,
}: Readonly<KoleksiTabsProps>) {
    return (
        <SectionTabs
            tabs={TABS}
            active={active}
            activeCount={activeCount}
            className={className}
        />
    );
}
