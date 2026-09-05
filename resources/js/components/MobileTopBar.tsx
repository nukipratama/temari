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
 * The app's top bar: the wordmark or a back chevron on the left, the bell and
 * avatar on the right, each in its own pill.
 *
 * In normal flow, not `fixed`. It floated for the whole F4 port, which meant
 * page content scrolled underneath it and showed through the gaps between the
 * pills — and in a standalone PWA iOS 26 blurs whatever lands in that strip, so
 * the bleed arrived pre-smeared. Nothing pinned can be painted over that region
 * reliably, so the bar stops competing for it: it reserves its own space at the
 * top of the page and scrolls away with everything else, which is also why
 * AppShell no longer carries a clearance padding.
 *
 * Sharing PageContainer's column rather than running full-bleed: in flow the
 * bar sits directly above the content, so at desktop widths, where the column
 * narrows and centres, chips pinned to the screen edges would read as belonging
 * to nothing.
 *
 * `max()` keeps the row clear of the notch where the inset resolves, and falls
 * back to 1rem in a browser tab, which has nothing to clear.
 */
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const back = backTargetFor(page.component);

    return (
        <header
            data-testid="mobile-top-bar"
            className="mx-auto flex w-full max-w-column items-center justify-between gap-3 px-4 pb-2.5 pt-[max(1rem,env(safe-area-inset-top))] min-[900px]:px-6 min-[1280px]:max-w-column-wide"
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
