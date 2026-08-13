import { usePage } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { lazy, type ReactNode, Suspense, useState } from 'react';

import type { SharedProps, UnlockFlash } from '@/types/inertia';

import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import AiOutageBanner from '@/components/AiOutageBanner';
import AccessoryUnlockModal from '@/components/celebrations/AccessoryUnlockModal';
import ErrorBanner from '@/components/ErrorBanner';
import FlashNotice from '@/components/FlashNotice';
import MobileBottomNav from '@/components/MobileBottomNav';
import MobileTopBar from '@/components/MobileTopBar';
import RouteProgressBar from '@/components/RouteProgressBar';
import StravaPausedBanner from '@/components/StravaPausedBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import UnlockToast from '@/components/temari/UnlockToast';
import TopNav from '@/components/TopNav';
import { useDawnShift } from '@/hooks/useDawnShift';
import { useSwipeBack } from '@/hooks/useSwipeBack';

// The pack reveal drags the whole share-card canvas engine in behind it, and
// this layout wraps every page — so it stays off the first-paint path and is
// fetched only when a reveal is actually pending.
const CardReveal = lazy(() => import('@/components/card/CardReveal'));

interface AppShellProps {
    children: ReactNode;
}

export default function AppShell({ children }: Readonly<AppShellProps>) {
    useDawnShift();
    useSwipeBack();
    const { pendingReveal, flash } = usePage<SharedProps>().props;
    const pending = pendingReveal ?? null;
    const unlock = flash?.unlock ?? null;
    const [majorUnlock, setMajorUnlock] = useState<UnlockFlash | null>(() =>
        unlock?.is_major ? unlock : null,
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

    return (
        <MotionConfig reducedMotion="user">
            {/* MobileTopBar carries the safe-area padding for this branch, so
            nothing is needed here — see its pt-[max(...)]. */}
            <div className="min-h-screen bg-cream-deep text-ink">
                <RouteProgressBar />
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-e2"
                >
                    Lompat ke konten
                </a>

                <TopNav />
                <MobileTopBar />

                <ErrorBanner />
                <FlashNotice />
                <StravaZoneReconnectBanner />
                <AiOutageBanner />
                <AiCatchingUpBanner />
                <StravaPausedBanner />

                {/* Deliberately unkeyed and unanimated. A `key` here forced React to
                tear down and rebuild the whole content subtree on every visit
                (25 card mounts on Collection), and the enter animation it existed
                to replay started at opacity 0 — so a navigation read as
                "old page → blank → fade in". Inertia already swaps a different
                component type on a real navigation, so React remounts what it
                needs to without help. */}
                <main id="main-content" className="pb-28 lg:pb-0">
                    {children}
                </main>

                <MobileBottomNav />
                {/* Celebration overlays are sequenced, not stacked: CardReveal (a pack
                reveal) takes priority over the accessory-unlock modal, which in turn
                takes priority over the UnlockToast, so a sync that fires more than
                one celebration plays them back-to-back instead of all at once. */}
                {!pending && majorUnlock === null && <UnlockToast />}
                {pending && (
                    <Suspense fallback={null}>
                        <CardReveal pending={pending} />
                    </Suspense>
                )}
                <AccessoryUnlockModal
                    unlock={pending ? null : majorUnlock}
                    onClose={() => setMajorUnlock(null)}
                />
            </div>
        </MotionConfig>
    );
}
