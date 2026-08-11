import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import { cn } from '@/lib/cn';

export interface SectionTabItem<TId extends string = string> {
    id: TId;
    label: string;
    href: string;
    icon: string;
}

interface SectionTabsProps<TId extends string> {
    tabs: ReadonlyArray<SectionTabItem<TId>>;
    active: TId;
    /** Shown as a count chip on the active tab only — sibling counts would
     *  require extra queries on every page load. */
    activeCount?: string;
    className?: string;
}

/**
 * Reusable sub-tab strip for a top-level nav item that folds in a second
 * page (e.g. Today/History, Plan/Race). Generalized from the earlier
 * `KoleksiTabs`/`RiwayatTabs` pair — same markup, same scroll-into-view
 * behavior, driven by a `tabs` prop instead of a hardcoded list.
 */
export default function SectionTabs<TId extends string>({
    tabs,
    active,
    activeCount,
    className,
}: Readonly<SectionTabsProps<TId>>) {
    const navRef = useRef<HTMLElement>(null);

    // The tab row scrolls horizontally on narrow screens; bring the active tab
    // fully into view on mount so a later tab isn't clipped off-screen.
    // `scrollIntoView({ inline: 'nearest' })` accounts for both edges of the
    // tab (label + count badge) — a manual scrollLeft based only on the left
    // edge could still leave the right edge clipped, or push an earlier tab
    // off-screen on the left.
    useEffect(() => {
        const nav = navRef.current;
        if (!nav) {
            return;
        }
        const activeEl = nav.querySelector<HTMLElement>(
            '[aria-current="page"]',
        );
        activeEl?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }, [active]);

    return (
        <nav
            ref={navRef}
            aria-label="Sub-tab"
            className={cn(
                'scrollbar-hide flex gap-1.5 overflow-x-auto',
                className,
            )}
        >
            {tabs.map((tab) => {
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
                        <Icon
                            icon={tab.icon}
                            width={14}
                            height={14}
                            aria-hidden
                        />
                        {tab.label}
                        {isActive &&
                            activeCount != null &&
                            activeCount !== '' && (
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
