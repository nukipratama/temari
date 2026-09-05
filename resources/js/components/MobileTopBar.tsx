import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import HeaderBrandMark from '@/components/HeaderBrandMark';
import NotificationBell from '@/components/NotificationBell';
import { Icon } from '@/components/ui/Icon';
import UserAvatarLink from '@/components/UserAvatarLink';
import { cn } from '@/lib/cn';
import { backTargetFor } from '@/lib/nav';

// A shared chip backdrop for the icon-only buttons — muted is the exact
// ground-reactive equivalent of the bar's old fixed cream-deep background (see
// AppShell), so NotificationBell/UserAvatarLink's own hover/ring styling
// (tuned against that backdrop) still reads correctly floating over content.
// Carried at 70% over a blur so the chips read as glass against whatever
// scrolls beneath them, matching the bottom nav's pill.
// Unsized on purpose: it hugs whichever of the two differently-sized controls
// it wraps rather than forcing both to match.
const CHIP =
    'inline-flex items-center justify-center overflow-hidden rounded-full bg-muted/70 shadow-e1 backdrop-blur-md';

/** Pushed screens whose prototype topbar keeps the bell beside the back chevron. */
const PUSHED_WITH_BELL: ReadonlySet<string> = new Set([
    'Profile',
    'Settings/Index',
]);

/**
 * Floating chips, per the prototype's AppTopbar/ProfileTopbar — `fixed` (not
 * `absolute` or `sticky`): the chips stay with the reader and never reserve
 * flow space, so AppShell's top padding remains the whole clearance contract.
 * The bar paints the ground colour, which is the same colour the page is
 * already painting behind it — so at rest it reads as the transparent bar it
 * used to be, and the only thing it changes is that content no longer scrolls
 * through the gaps between the chips. That bleed was legible page text sliding
 * under the clock, and iOS 26 blurs whatever sits in that strip. Full-bleed at every
 * width, as the prototype's own chrome is: above 900px the content column
 * narrows to 760px and the chips sit outside it, which is what lets the
 * column's top padding shrink to `pt-6`. `max()` keeps the row clear of the
 * notch under black-translucent; falls back to 1rem in a browser tab.
 */
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const back = backTargetFor(page.component);

    return (
        <header
            data-testid="mobile-top-bar"
            className="fixed inset-x-0 top-0 z-30 flex items-center justify-between gap-3 bg-background px-4 pb-2.5 pt-[max(1rem,env(safe-area-inset-top))]"
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
                    className="pressable focus-ring inline-flex items-center gap-2.5 rounded-full bg-muted/70 py-1.75 pr-3.25 pl-2.5 shadow-e1 backdrop-blur-md"
                >
                    <HeaderBrandMark wordmarkClassName="hidden min-[350px]:inline" />
                </Link>
            )}

            <div className="flex items-center gap-2">
                {page.component === 'Profile' && (
                    <Link
                        href="/settings"
                        aria-label="Settings"
                        className={cn(
                            CHIP,
                            'pressable focus-ring size-9 text-text-3',
                        )}
                    >
                        <Icon
                            icon="mdi:cog-outline"
                            width={18}
                            height={18}
                            aria-hidden
                        />
                    </Link>
                )}
                {user &&
                    (back === null || PUSHED_WITH_BELL.has(page.component)) && (
                        <span className={CHIP}>
                            <NotificationBell density="compact" />
                        </span>
                    )}
                {user && back === null && (
                    <span className={CHIP}>
                        <UserAvatarLink
                            name={user.name}
                            avatarUrl={user.avatar_url}
                        />
                    </span>
                )}
            </div>
        </header>
    );
}
