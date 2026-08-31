import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import HeaderBrandMark from '@/components/HeaderBrandMark';
import NotificationBell from '@/components/NotificationBell';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import { Icon } from '@/components/ui/Icon';
import UserAvatarLink from '@/components/UserAvatarLink';
import { cn } from '@/lib/cn';

// Explicit map (not derived from activeTabFromUrl): calendar/records/accessories/badges/race
// resolve to a tab too, but reach it via an in-page tab strip, so they keep the brand mark.
const BACK_TARGETS: Record<string, { href: string; label: string }> = {
    'Runs/Show': { href: '/history', label: 'History' },
};

// A shared chip backdrop for the icon-only buttons — bg-muted is the exact
// ground-reactive equivalent of the bar's old fixed cream-deep background (see
// AppShell), so NotificationBell/UserAvatarLink's own hover/ring styling
// (tuned against that backdrop) still reads correctly floating over content.
// Unsized on purpose: it hugs whichever of the two differently-sized controls
// it wraps rather than forcing both to match.
const CHIP =
    'inline-flex items-center justify-center overflow-hidden rounded-full bg-muted shadow-e1';

/**
 * Floating transparent chips, per the prototype's AppTopbar/ProfileTopbar —
 * replaces the previous sticky bordered bar. `absolute` (not `sticky`): the
 * bar no longer reserves flow space or paints a background, so content
 * scrolls underneath it — AppShell reserves the clearance with top padding
 * instead. `max()` keeps the row clear of the notch under black-translucent;
 * falls back to 1rem in a browser tab.
 */
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const stravaSync = page.props.stravaSync ?? null;
    const back = BACK_TARGETS[page.component];

    return (
        <header
            data-testid="mobile-top-bar"
            className="absolute inset-x-0 top-0 z-30 flex items-center justify-between gap-3 px-4 pb-2.5 pt-[max(1rem,env(safe-area-inset-top))] lg:hidden"
        >
            {back ? (
                // Real href, not history.back(): a deep link can open this cold with nothing behind it.
                <Link
                    href={back.href}
                    aria-label={`Back to ${back.label}`}
                    className={cn(
                        CHIP,
                        'pressable focus-ring size-9 text-foreground',
                    )}
                >
                    <Icon
                        icon="mdi:arrow-left"
                        width={18}
                        height={18}
                        aria-hidden
                    />
                </Link>
            ) : (
                <Link
                    href="/"
                    aria-label="Home"
                    className="pressable focus-ring inline-flex items-center gap-2.5 rounded-full bg-muted py-1.75 pr-3.25 pl-2.5 shadow-e1"
                >
                    <HeaderBrandMark wordmarkClassName="hidden min-[350px]:inline" />
                </Link>
            )}
            <div className="flex items-center gap-2">
                <StravaSyncBadge sync={stravaSync} density="compact" />
                {user && (
                    <>
                        <span className={CHIP}>
                            <NotificationBell density="compact" />
                        </span>
                        <span className={CHIP}>
                            <UserAvatarLink
                                name={user.name}
                                avatarUrl={user.avatar_url}
                            />
                        </span>
                    </>
                )}
            </div>
        </header>
    );
}
