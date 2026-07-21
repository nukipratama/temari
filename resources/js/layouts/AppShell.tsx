import { type ReactNode, useState } from 'react';
import { MotionConfig } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import UnlockToast from '@/components/temari/UnlockToast';
import CardReveal from '@/components/card/CardReveal';
import AksesoriUnlockModal from '@/components/celebrations/AksesoriUnlockModal';
import TopNav from '@/components/TopNav';
import MobileTopBar from '@/components/MobileTopBar';
import MobileBottomNav from '@/components/MobileBottomNav';
import ErrorBanner from '@/components/ErrorBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import AiOutageBanner from '@/components/AiOutageBanner';
import { useDawnShift } from '@/hooks/useDawnShift';
import { cn } from '@/lib/cn';
import { useSwipeBack } from '@/hooks/useSwipeBack';
import type { SharedProps, UnlockFlash } from '@/types/inertia';

/** Inertia page name of the profile tab — the only page that keeps the mobile top bar. */
const MOBILE_TOP_BAR_PAGE = 'Aku';

interface AppShellProps {
    children: ReactNode;
    /** Hides the TopNav + MobileBottomNav for standalone screens (e.g. Login). */
    withNav?: boolean;
}

export default function AppShell({ children, withNav = true }: Readonly<AppShellProps>) {
    useDawnShift();
    useSwipeBack();
    const page = usePage<SharedProps>();
    const { pendingReveal, flash } = page.props;
    const pending = pendingReveal ?? null;

    // The mobile top bar earns its space on the profile tab, where the account
    // menu belongs, and nowhere else: on the other tabs it was permanent chrome
    // for a decorative brand mark and an ambient sync chip. Desktop is
    // unaffected — TopNav carries navigation there and is a different component.
    const showMobileTopBar = page.component === MOBILE_TOP_BAR_PAGE;
    const unlock = flash?.unlock ?? null;
    const [majorUnlock, setMajorUnlock] = useState<UnlockFlash | null>(
        () => (unlock?.is_major ? unlock : null),
    );
    const [lastUnlock, setLastUnlock] = useState(unlock);

    // Capture a major unlock flash for the reveal — adjusted during render
    // (React-endorsed) so the sync setState isn't inside an effect.
    if (unlock !== lastUnlock) {
        setLastUnlock(unlock);
        if (unlock?.is_major) {
            setMajorUnlock(unlock);
        }
    }

    if (!withNav) {
        return (
            <MotionConfig reducedMotion="user">
                {/* No MobileTopBar here, so this branch pads for the notch
                    itself. */}
                <div className="min-h-screen bg-cream-deep pt-[env(safe-area-inset-top)] text-ink">
                    <ErrorBanner />
                    <StravaZoneReconnectBanner />
                    <AiOutageBanner />
                    {children}
                </div>
            </MotionConfig>
        );
    }

    return (
        <MotionConfig reducedMotion="user">
        <div
            className={cn(
                'min-h-screen bg-cream-deep text-ink',
                // With no top bar on this page, nothing else keeps content
                // clear of the notch — under `black-translucent` the web view
                // runs edge to edge. Mobile only: the inset is 0 on desktop
                // anyway, but TopNav owns the top there regardless.
                !showMobileTopBar && 'pt-[env(safe-area-inset-top)] lg:pt-0',
            )}
        >
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
            >
                Lompat ke konten
            </a>

            <TopNav />
            {showMobileTopBar && <MobileTopBar />}

            <ErrorBanner />
            <StravaZoneReconnectBanner />
            <AiOutageBanner />

            {/* Deliberately unkeyed and unanimated. A `key` here forced React to
                tear down and rebuild the whole content subtree on every visit
                (25 card mounts on Koleksi), and the enter animation it existed
                to replay started at opacity 0 — so a navigation read as
                "old page → blank → fade in". Inertia already swaps a different
                component type on a real navigation, so React remounts what it
                needs to without help. */}
            <main id="main-content" className="pb-28 lg:pb-0">
                {children}
            </main>

            <MobileBottomNav />
            {/* Celebration overlays are sequenced, not stacked: CardReveal (a pack
                reveal) takes priority over the aksesori-unlock modal, which in turn
                takes priority over the UnlockToast, so a sync that fires more than
                one celebration plays them back-to-back instead of all at once. */}
            {!pending && majorUnlock === null && <UnlockToast />}
            {pending && <CardReveal pending={pending} />}
            <AksesoriUnlockModal unlock={pending ? null : majorUnlock} onClose={() => setMajorUnlock(null)} />
        </div>
        </MotionConfig>
    );
}
