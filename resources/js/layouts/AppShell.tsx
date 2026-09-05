import type { ReactNode } from 'react';

import { usePage } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';

import type { SharedProps } from '@/types/inertia';

import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import AiOutageBanner from '@/components/AiOutageBanner';
import ErrorBanner from '@/components/ErrorBanner';
import FlashNotice from '@/components/FlashNotice';
import MobileBottomNav from '@/components/MobileBottomNav';
import MobileTopBar from '@/components/MobileTopBar';
import RouteProgressBar from '@/components/RouteProgressBar';
import StatusBarScrim from '@/components/StatusBarScrim';
import StravaPausedBanner from '@/components/StravaPausedBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import { useSwipeBack } from '@/hooks/useSwipeBack';
import { useSystemTheme } from '@/hooks/useSystemTheme';
import { cn } from '@/lib/cn';
import { navTabFor } from '@/lib/nav';

interface AppShellProps {
    children: ReactNode;
}

export default function AppShell({ children }: Readonly<AppShellProps>) {
    useSwipeBack();
    useSystemTheme();
    const { component } = usePage<SharedProps>();
    const hasBottomNav = navTabFor(component) !== null;

    return (
        <MotionConfig reducedMotion="user">
            <div className="min-h-screen bg-background text-foreground">
                <RouteProgressBar />
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-e2"
                >
                    Skip to content
                </a>

                <StatusBarScrim />
                <MobileTopBar />

                {/* MobileTopBar is fixed and out of flow (see its own comment), so
                this padding is what actually clears it. Above 900px the column narrows
                to 760px and the bar's chips sit outside it, so the clearance drops to
                the prototype's own pt-6. */}
                <div className="pt-[max(4rem,calc(env(safe-area-inset-top)+3rem))] min-[900px]:pt-6">
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
                    <main
                        id="main-content"
                        tabIndex={-1}
                        className={cn(
                            'outline-none',
                            hasBottomNav
                                ? 'pb-28'
                                : 'pb-[calc(1.75rem+env(safe-area-inset-bottom))]',
                        )}
                    >
                        {children}
                    </main>
                </div>

                <MobileBottomNav />
            </div>
        </MotionConfig>
    );
}
