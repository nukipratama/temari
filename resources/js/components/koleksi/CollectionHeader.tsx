import Eyebrow from '@/components/ui/Eyebrow';
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
            <div>
                <Eyebrow token="hero" tone="ink-2" className="mb-3.5">
                    {eyebrow}
                </Eyebrow>
                <h1 className="font-display text-display-lg text-ink">
                    {headline1},<br />
                    <em className="italic text-horizon-deep">{headline2}</em>
                </h1>
            </div>
            <KoleksiTabs active={active} activeCount={activeCount} />
        </header>
    );
}
