import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

export type KoleksiTab = 'kartu' | 'rekor' | 'aksesori' | 'target';

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
    { id: 'target', label: 'Target', href: '/target' },
];

export default function KoleksiTabs({ active, activeCount, className }: Readonly<KoleksiTabsProps>) {
    return (
        <nav aria-label="Sub-tab" className={cn('scrollbar-hide flex gap-1.5 overflow-x-auto', className)}>
            {TABS.map((tab) => {
                const isActive = active === tab.id;
                return (
                    <Link
                        key={tab.id}
                        href={tab.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'focus-ring inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-4 py-2.5 text-[13px] font-medium transition',
                            isActive
                                ? 'border border-transparent bg-sky text-cream font-semibold'
                                : 'border border-line bg-sky/[0.04] text-ink-2 hover:bg-sky/[0.1]',
                        )}
                    >
                        {tab.label}
                        {isActive && activeCount != null && activeCount !== '' && (
                            <span className="rounded-full bg-horizon/25 px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-[0.06em] text-horizon">
                                {activeCount}
                            </span>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
