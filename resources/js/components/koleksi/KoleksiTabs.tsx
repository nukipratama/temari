import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

export type KoleksiTab = 'kartu' | 'rekor' | 'aksesori';

interface TabItem {
    id: KoleksiTab;
    label: string;
    href: string;
}

interface KoleksiTabsProps {
    active: KoleksiTab;
    /** Shown as a count chip on the active tab only — sibling counts would
     *  require extra queries on every page load. */
    activeCount?: string;
    className?: string;
}

const TABS: ReadonlyArray<TabItem> = [
    { id: 'kartu', label: 'Kartu', href: '/kartu' },
    { id: 'rekor', label: 'Rekor', href: '/rekor' },
    { id: 'aksesori', label: 'Aksesori', href: '/aksesori' },
];

export default function KoleksiTabs({ active, activeCount, className }: Readonly<KoleksiTabsProps>) {
    return (
        <nav aria-label="Sub-tab" className={cn('flex flex-wrap gap-1.5', className)}>
            {TABS.map((tab) => {
                const isActive = active === tab.id;
                return (
                    <Link
                        key={tab.id}
                        href={tab.href}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-[13px] font-medium transition',
                            isActive
                                ? 'bg-sky text-cream font-semibold'
                                : 'bg-transparent text-ink-2 hover:bg-sky/[0.06]',
                        )}
                    >
                        {tab.label}
                        {isActive && activeCount != null && activeCount !== '' && (
                            <span className="rounded-full bg-horizon/25 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-[0.06em] text-horizon">
                                {activeCount}
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
