import SectionTabs, { type SectionTabItem } from '@/components/ui/SectionTabs';

export type CollectionTab = 'cards' | 'records' | 'accessories' | 'badges';

interface CollectionTabsProps {
    active: CollectionTab;
    /** Active tab's count chip only — sibling counts would need extra queries per page load. */
    activeCount?: string;
    className?: string;
}

const TABS: ReadonlyArray<SectionTabItem<CollectionTab>> = [
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

export default function CollectionTabs({
    active,
    activeCount,
    className,
}: Readonly<CollectionTabsProps>) {
    return (
        <SectionTabs
            tabs={TABS}
            active={active}
            activeCount={activeCount}
            className={className}
        />
    );
}
