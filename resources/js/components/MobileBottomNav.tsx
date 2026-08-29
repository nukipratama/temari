import type { ComponentType, MouseEvent, SVGProps } from 'react';

import { Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { CalendarCheck, History, LineChart, Sunrise } from 'lucide-react';

import type { SharedProps } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { tabIconPop } from '@/lib/motion';
import { activeTabFromUrl, ITEMS } from '@/lib/nav';

// Keyed by NavItem.icon (a lucide component name, not an iconify string — see
// nav.ts) rather than the shared Icon wrapper: the wrapper's mdi:xxx lookup
// exists to preserve a backend-driven prop shape (UnlockFlash.icon) that this
// fixed, 4-item nav list has no equivalent of.
const ICONS: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    Sunrise,
    CalendarCheck,
    LineChart,
    History,
};

// Tapping the active tab scrolls to top instead of a full Inertia round-trip to the same page.
function scrollToTop(event: MouseEvent<Element>) {
    event.preventDefault();
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
}

/**
 * Floating frosted-glass pill, per the prototype's AppBottomNav — replaces
 * the previous full-width solid bar. `bottom-[max(...)]` positions the whole
 * pill clear of the home-indicator area rather than padding inside a
 * full-bleed bar, since the pill no longer touches the screen edges.
 */
export default function MobileBottomNav() {
    const { url } = usePage<SharedProps>();
    const active = activeTabFromUrl(url);

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-3.5 bottom-[max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))] z-30 flex gap-1 rounded-full border border-white/30 bg-card/60 p-1.5 shadow-e2 backdrop-blur-xl backdrop-saturate-150 lg:hidden"
        >
            {ITEMS.map((item) => {
                const isActive = active === item.id;
                const TabIcon = ICONS[item.icon];
                return (
                    <Link
                        key={item.id}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        onClick={isActive ? scrollToTop : undefined}
                        className={cn(
                            'pressable focus-ring flex flex-1 items-center justify-center gap-1.5 rounded-full py-2.5 text-text-3 no-underline transition-[flex-grow,background-color,color] duration-150',
                            isActive &&
                                'grow-[1.6] bg-gradient-to-br from-horizon/34 to-horizon/14 text-icon-accent shadow-[0_2px_10px_-2px_rgba(173,224,71,.45),inset_0_1px_0_rgba(255,255,255,.25)]',
                        )}
                    >
                        <motion.span
                            variants={tabIconPop}
                            animate={isActive ? 'active' : 'idle'}
                            className="block"
                        >
                            {TabIcon && (
                                <TabIcon
                                    className={cn(
                                        'transition-[width,height] duration-150',
                                        isActive ? 'size-5' : 'size-[18px]',
                                    )}
                                    aria-hidden
                                />
                            )}
                        </motion.span>
                        <span
                            className={cn(
                                'overflow-hidden font-mono text-[9px] font-extrabold tracking-[.05em] uppercase transition-[max-width,opacity] duration-200',
                                isActive
                                    ? 'max-w-[60px] opacity-100'
                                    : 'max-w-0 opacity-0',
                            )}
                        >
                            {item.label}
                        </span>
                    </Link>
                );
            })}
        </nav>
    );
}
