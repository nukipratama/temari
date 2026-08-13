import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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
    /** Active tab's count chip only — sibling counts would need extra queries per page load. */
    activeCount?: string;
    /** Cream-on-dark treatment for use on a HeroPanel/sky background. */
    onSky?: boolean;
    className?: string;
}

type Fade = 'none' | 'start' | 'end' | 'both';

const FADE_CLASS: Record<Fade, string | undefined> = {
    none: undefined,
    start: '[mask-image:linear-gradient(to_right,transparent_0,#000_28px)]',
    end: '[mask-image:linear-gradient(to_right,#000_calc(100%_-_28px),transparent_100%)]',
    both: '[mask-image:linear-gradient(to_right,transparent_0,#000_28px,#000_calc(100%_-_28px),transparent_100%)]',
};

function fadeFor(nav: HTMLElement): Fade {
    const overflow = nav.scrollWidth - nav.clientWidth;
    if (overflow <= 0) {
        return 'none';
    }
    const start = nav.scrollLeft > 1;
    const end = nav.scrollLeft < overflow - 1;
    if (start && end) {
        return 'both';
    }
    return start ? 'start' : 'end';
}

/** Sub-tab strip for a top-level nav item that folds in a second page (e.g. Today/History). */
export default function SectionTabs<TId extends string>({
    tabs,
    active,
    activeCount,
    onSky = false,
    className,
}: Readonly<SectionTabsProps<TId>>) {
    const navRef = useRef<HTMLElement>(null);
    const [fade, setFade] = useState<Fade>('none');

    useEffect(() => {
        const nav = navRef.current;
        if (!nav) {
            return;
        }
        const sync = () => setFade(fadeFor(nav));
        nav.addEventListener('scroll', sync, { passive: true });
        // ResizeObserver delivers a first callback on observe(), which doubles as
        // the initial measurement.
        const observer = new ResizeObserver(sync);
        observer.observe(nav);
        return () => {
            nav.removeEventListener('scroll', sync);
            observer.disconnect();
        };
    }, [tabs, active]);

    // scrollIntoView (not a manual scrollLeft calc) covers both edges of the active tab.
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
                FADE_CLASS[fade],
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
                            isActive &&
                                (onSky
                                    ? 'bg-cream text-sky font-semibold shadow-e1'
                                    : 'bg-sky text-cream font-semibold shadow-e1'),
                            !isActive &&
                                (onSky
                                    ? 'bg-transparent text-ink-on-sky hover:bg-cream/10 hover:text-cream'
                                    : 'bg-transparent text-ink-2 hover:bg-sky/[0.06]'),
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
                                <span
                                    className={cn(
                                        'rounded-full px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-[0.06em]',
                                        onSky
                                            ? 'bg-sky/15 text-sky'
                                            : 'bg-horizon/25 text-horizon',
                                    )}
                                >
                                    {activeCount}
                                </span>
                            )}
                    </Link>
                );
            })}
        </nav>
    );
}
