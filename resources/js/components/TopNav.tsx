import type { ComponentType, SVGProps } from 'react';

import { Link, usePage } from '@inertiajs/react';
import { CalendarCheck, History, LineChart, Sunrise } from 'lucide-react';

import type { SharedProps } from '@/types/inertia';

import HeaderBrandMark from '@/components/HeaderBrandMark';
import NotificationBell from '@/components/NotificationBell';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserAvatarLink from '@/components/UserAvatarLink';
import { cn } from '@/lib/cn';
import { activeTabFromUrl, ITEMS, type NavItem } from '@/lib/nav';

// Keyed by NavItem.icon, same convention as MobileBottomNav's own map.
const ICONS: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    Sunrise,
    CalendarCheck,
    LineChart,
    History,
};

export default function TopNav() {
    const { url, props } = usePage<SharedProps>();
    const active = activeTabFromUrl(url);
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;

    return (
        <header className="sticky top-0 z-30 hidden px-6 pt-6 pb-3 lg:block">
            <div className="mx-auto flex w-full max-w-page items-center justify-between rounded-full border border-white/30 bg-card/60 py-2 pr-3.5 pl-4 shadow-e2 backdrop-blur-xl backdrop-saturate-150 2xl:max-w-page-2xl">
                <div className="flex items-center gap-7">
                    <Link
                        href="/"
                        aria-label="Home"
                        className="focus-ring rounded"
                    >
                        <HeaderBrandMark />
                    </Link>
                    <nav
                        aria-label="Primary"
                        className="flex items-center gap-1"
                    >
                        {ITEMS.map((item) => (
                            <TabLink
                                key={item.id}
                                item={item}
                                isActive={active === item.id}
                            />
                        ))}
                    </nav>
                </div>
                <div className="flex items-center gap-3.5">
                    <StravaSyncBadge sync={stravaSync} />
                    {user && (
                        <>
                            <NotificationBell />
                            <UserAvatarLink
                                name={user.name}
                                avatarUrl={user.avatar_url}
                            />
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}

function TabLink({
    item,
    isActive,
}: Readonly<{ item: NavItem; isActive: boolean }>) {
    const TabIcon = ICONS[item.icon];

    return (
        <Link
            href={item.href}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'pressable focus-ring flex items-center gap-2 rounded-full px-4 py-2 font-mono text-sm font-bold tracking-[0.02em] transition-colors',
                isActive
                    ? 'bg-gradient-to-br from-horizon/34 to-horizon/14 text-icon-accent shadow-[0_2px_10px_-2px_rgba(173,224,71,.45),inset_0_1px_0_rgba(255,255,255,.25)]'
                    : 'text-text-3 hover:text-text-2',
            )}
        >
            {TabIcon && <TabIcon className="size-[18px]" aria-hidden />}
            {item.label}
        </Link>
    );
}
