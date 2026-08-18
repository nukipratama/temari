import { Icon } from '@iconify/react';
import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import BrandMark from '@/components/BrandMark';
import NotificationBell from '@/components/NotificationBell';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserMenu from '@/components/UserMenu';
import { useScrolled } from '@/hooks/useScrolled';
import { cn } from '@/lib/cn';

// Explicit map (not derived from activeTabFromUrl): calendar/records/accessories/badges/race
// resolve to a tab too, but reach it via an in-page tab strip, so they keep the brand mark.
const BACK_TARGETS: Record<string, { href: string; label: string }> = {
    'Runs/Show': { href: '/history', label: 'History' },
    // Settings is one tap from Me/avatar menu everywhere, so it stays a root, not a push.
    'Settings/HrZones': { href: '/settings', label: 'Settings' },
};

// max() keeps the row clear of the notch under black-translucent; falls back to 0.75rem in a browser tab.
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const stravaSync = page.props.stravaSync ?? null;
    const scrolled = useScrolled();
    const back = BACK_TARGETS[page.component];

    return (
        <header
            data-testid="mobile-top-bar"
            className={cn(
                'sticky top-0 z-30 flex items-center justify-between gap-3 border-b bg-cream-deep/85 px-5 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] backdrop-blur-xl transition-colors lg:hidden',
                scrolled ? 'border-line' : 'border-transparent',
            )}
        >
            {back ? (
                // Real href, not history.back(): a deep link can open this cold with nothing behind it.
                <Link
                    href={back.href}
                    aria-label={`Back to ${back.label}`}
                    className="pressable focus-ring -ml-1 inline-flex min-w-0 items-center gap-1 rounded py-1 pl-1 pr-2 text-label-small text-ink-2 transition hover:text-ink"
                >
                    <Icon
                        icon="mdi:chevron-left"
                        width={18}
                        height={18}
                        aria-hidden
                        className="shrink-0"
                    />
                    <span className="truncate">{back.label}</span>
                </Link>
            ) : (
                <Link href="/" aria-label="Home" className="focus-ring rounded">
                    <BrandMark wordmarkClassName="hidden min-[350px]:inline" />
                </Link>
            )}
            <div className="flex items-center gap-2">
                <StravaSyncBadge sync={stravaSync} density="compact" />
                {user && (
                    <>
                        <NotificationBell density="compact" />
                        <UserMenu
                            name={user.name}
                            avatarUrl={user.avatar_url}
                        />
                    </>
                )}
            </div>
        </header>
    );
}
