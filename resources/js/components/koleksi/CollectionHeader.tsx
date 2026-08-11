import PageHero from '@/components/ui/PageHero';

import KoleksiTabs, { type KoleksiTab } from './KoleksiTabs';

interface CollectionHeaderProps {
    active: KoleksiTab;
    eyebrow: string;
    headline1: string;
    headline2: string;
    /** Count chip rendered on the currently-active sub-tab. */
    activeCount?: string;
}

export default function CollectionHeader({
    active,
    eyebrow,
    headline1,
    headline2,
    activeCount,
}: Readonly<CollectionHeaderProps>) {
    return (
        <header className="flex flex-col gap-5">
            <PageHero eyebrow={eyebrow}>
                {headline1},<br />
                <em className="italic text-horizon-deep">{headline2}</em>
            </PageHero>
            <div data-coachmark="collection-tabs">
                <KoleksiTabs active={active} activeCount={activeCount} />
            </div>
        </header>
    );
}
