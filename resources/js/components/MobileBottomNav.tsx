import type { MouseEvent } from 'react';

import { Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';

import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { tabIconPop } from '@/lib/motion';
import { activeTabFromUrl, ITEMS } from '@/lib/nav';

// Tapping the active tab scrolls to top instead of a full Inertia round-trip to the same page.
function scrollToTop(event: MouseEvent<Element>) {
    event.preventDefault();
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
}

export default function MobileBottomNav() {
    const { url } = usePage<SharedProps>();
    const active = activeTabFromUrl(url);

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-30 flex justify-around rounded-t-[18px] bg-sky px-2 pb-[max(1.75rem,env(safe-area-inset-bottom))] pt-2.5 lg:hidden"
        >
            {ITEMS.map((item) => {
                const isActive = active === item.id;
                return (
                    <Link
                        key={item.id}
                        href={item.href}
                        className={cn(
                            'pressable focus-ring-on-sky flex flex-col items-center gap-1 rounded-lg px-4 py-2 transition-colors',
                            isActive ? 'text-horizon' : 'text-ink-on-sky',
                        )}
                        aria-current={isActive ? 'page' : undefined}
                        onClick={isActive ? scrollToTop : undefined}
                    >
                        <motion.span
                            variants={tabIconPop}
                            animate={isActive ? 'active' : 'idle'}
                            className="block"
                        >
                            <Icon
                                icon={item.icon}
                                width={20}
                                height={20}
                                aria-hidden
                            />
                        </motion.span>
                        <span
                            className={cn(
                                'text-[11px]',
                                isActive ? 'font-semibold' : 'font-normal',
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
