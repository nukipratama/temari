import { CalendarCheck, History, LineChart, Sunrise } from 'lucide-react';
import type { ComponentType } from 'react';

import { cn } from '@/lib/utils';

const ITEMS = [
    { key: 'today', label: 'today', icon: Sunrise },
    { key: 'plan', label: 'plan', icon: CalendarCheck },
    { key: 'trends', label: 'trends', icon: LineChart },
    { key: 'history', label: 'history', icon: History },
] as const;

type NavKey = (typeof ITEMS)[number]['key'];

function NavItem({
    icon: Icon,
    label,
    active,
}: Readonly<{
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    label: string;
    active: boolean;
}>) {
    return (
        <a
            href="#"
            className={cn(
                'flex flex-1 items-center justify-center gap-1.5 rounded-full py-2.5 text-text-3 no-underline transition-[background-color,color,flex-grow] duration-150',
                active &&
                    'grow-[1.6] bg-gradient-to-br from-horizon/34 to-horizon/14 text-icon-accent shadow-[0_2px_10px_-2px_rgba(173,224,71,.45),inset_0_1px_0_rgba(255,255,255,.25)]',
            )}
        >
            <Icon
                className={cn(
                    'transition-[font-size] duration-150',
                    active ? 'size-5' : 'size-[18px]',
                )}
                aria-hidden
            />
            <span
                className={cn(
                    'overflow-hidden font-mono text-[9px] font-extrabold tracking-[.05em] uppercase transition-[max-width,opacity] duration-200',
                    active ? 'max-w-[60px] opacity-100' : 'max-w-0 opacity-0',
                )}
            >
                {label}
            </span>
        </a>
    );
}

/**
 * Shared post-auth chrome — the mockups' .bottomnav.
 * Frosted glass floating over content; the original HTML source referenced an
 * undefined --e2 var in its box-shadow, which invalidated that whole
 * declaration (verified: computed box-shadow was "none") — no shadow here
 * either, matching what actually rendered rather than the apparent intent.
 */
export function AppBottomNav({ active }: Readonly<{ active: NavKey }>) {
    return (
        <nav className="absolute right-3.5 bottom-3.5 left-3.5 z-10 flex gap-1 rounded-full border border-white/30 bg-[linear-gradient(180deg,rgba(255,255,255,.24),rgba(255,255,255,.05)),color-mix(in_oklab,var(--card)_58%,transparent)] p-1.5 backdrop-blur-xl backdrop-saturate-[1.8]">
            {ITEMS.map((item) => (
                <NavItem
                    key={item.key}
                    icon={item.icon}
                    label={item.label}
                    active={item.key === active}
                />
            ))}
        </nav>
    );
}
