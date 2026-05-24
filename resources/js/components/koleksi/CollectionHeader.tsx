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
                <div className="mb-3.5 font-mono text-[11px] uppercase tracking-[0.18em] text-ink-3">
                    {eyebrow}
                </div>
                <h1 className="font-display text-[44px] leading-[0.95] tracking-[-0.025em] text-ink sm:text-[56px] lg:text-[72px] lg:leading-[0.92]">
                    {headline1},<br />
                    <em className="italic text-horizon-deep">{headline2}</em>
                </h1>
            </div>
            <KoleksiTabs active={active} activeCount={activeCount} />
        </header>
    );
}
