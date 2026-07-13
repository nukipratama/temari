import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/cn';

export type KoleksiTab = 'kartu' | 'rekor' | 'aksesori' | 'target';

interface TabItem {
    id: KoleksiTab;
    label: string;
    href: string;
    icon: string;
}

interface KoleksiTabsProps {
    active: KoleksiTab;
    /** Shown as a count chip on the active tab only — sibling counts would
     *  require extra queries on every page load. */
    activeCount?: string;
    className?: string;
}

const TABS: ReadonlyArray<TabItem> = [
    { id: 'kartu', label: 'Kartu', href: '/kartu', icon: 'mdi:cards-outline' },
    { id: 'rekor', label: 'Rekor', href: '/rekor', icon: 'mdi:trophy-outline' },
    { id: 'aksesori', label: 'Aksesori', href: '/aksesori', icon: 'mdi:tshirt-crew-outline' },
    { id: 'target', label: 'Target', href: '/target', icon: 'mdi:target' },
];

export default function KoleksiTabs({ active, activeCount, className }: Readonly<KoleksiTabsProps>) {
    const navRef = useRef<HTMLElement>(null);

    // The tab row scrolls horizontally on narrow screens; bring the active tab
    // into view on mount so a later tab (e.g. "Target") isn't clipped off-screen.
    useEffect(() => {
        const nav = navRef.current;
        if (!nav) {
            return;
        }
        const activeEl = nav.querySelector<HTMLElement>('[aria-current="page"]');
        if (activeEl) {
            const navRect = nav.getBoundingClientRect();
            const elRect = activeEl.getBoundingClientRect();
            nav.scrollLeft += elRect.left - navRect.left - 16;
        }
    }, [active]);

    return (
        <nav ref={navRef} aria-label="Sub-tab" className={cn('scrollbar-hide flex gap-1.5 overflow-x-auto', className)}>
            {TABS.map((tab) => {
                const isActive = active === tab.id;
                return (
                    <Link
                        key={tab.id}
                        href={tab.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'focus-ring inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-2 text-[13px] transition',
                            isActive
                                ? 'bg-sky text-cream font-semibold shadow-sm'
                                : 'bg-transparent text-ink-2 hover:bg-sky/[0.06]',
                        )}
                    >
                        <Icon icon={tab.icon} width={14} height={14} aria-hidden />
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
