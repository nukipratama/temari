import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Settings } from 'lucide-react';
import { useSyncExternalStore } from 'react';

import type { SharedProps } from '@/types/inertia';

import HeaderBrandMark from '@/components/HeaderBrandMark';
import NotificationBell from '@/components/NotificationBell';
import { Icon } from '@/components/ui/Icon';
import UserAvatarLink from '@/components/UserAvatarLink';
import { cn } from '@/lib/cn';
import { backTargetFor } from '@/lib/nav';
import {
    contextualOriginSnapshot,
    parseContextualOrigin,
    subscribeToContextualOrigin,
} from '@/lib/navigationMemory';
import { pendingTabSnapshot, subscribePendingTab } from '@/lib/pendingTab';
import { iconButtonVariants } from '@/lib/variants';

// A shared chip backdrop for the icon-only buttons: muted over a blur, so the
// chips read as glass against whatever scrolls beneath them.
// Unsized on purpose: it hugs whichever of the two differently-sized controls
// it wraps.
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
 * In normal flow, not `fixed`, so page content never scrolls underneath it. It
 * shares PageContainer's column so the chips do not pin to the screen edges at
 * desktop widths; `max()` keeps the row clear of the notch.
 */
export default function MobileTopBar() {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const storedOrigin = useSyncExternalStore(
        subscribeToContextualOrigin,
        contextualOriginSnapshot,
        () => null,
    );
    const pending = useSyncExternalStore(
        subscribePendingTab,
        pendingTabSnapshot,
        () => null,
    );
    const screen = pending === null ? page.component : pending.screen;
    const back =
        screen === null
            ? null
            : backTargetFor(screen, parseContextualOrigin(storedOrigin));

    return (
        <header
            data-testid="mobile-top-bar"
            className="mx-auto flex w-full min-[900px]:max-w-column items-center justify-between gap-3 px-4 pt-[max(1rem,env(safe-area-inset-top))] min-[900px]:px-6 min-[1280px]:max-w-column-wide"
        >
            {back ? (
                // Real href, not history.back(): a deep link can open this cold with nothing behind it.
                <Link
                    href={back.href}
                    aria-label={`Back to ${back.label}`}
                    className={cn(
                        CHIP,
                        iconButtonVariants(),
                        'text-foreground',
                    )}
                >
                    <Icon icon={ArrowLeft} width={18} height={18} aria-hidden />
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
                {screen === 'Profile' && (
                    <Link
                        href="/settings"
                        aria-label="Settings"
                        className={cn(
                            CHIP,
                            'pressable focus-ring size-9 text-text-3',
                        )}
                    >
                        <Icon
                            icon={Settings}
                            width={18}
                            height={18}
                            aria-hidden
                        />
                    </Link>
                )}
                {user &&
                    (back === null || PUSHED_WITH_BELL.has(screen ?? '')) && (
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
