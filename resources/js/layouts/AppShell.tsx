import type { ComponentType, ReactNode } from 'react';

import { usePage } from '@inertiajs/react';

import type { TabId } from '@/lib/nav';
import type { SharedProps } from '@/types/inertia';

import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import ErrorBanner from '@/components/ErrorBanner';
import FlashNotice from '@/components/FlashNotice';
import HistorySkeleton from '@/components/history/HistorySkeleton';
import TodaySkeleton from '@/components/home/TodaySkeleton';
import MobileBottomNav from '@/components/MobileBottomNav';
import MobileTopBar from '@/components/MobileTopBar';
import PlanSkeleton from '@/components/plan/PlanSkeleton';
import PullToRefresh from '@/components/PullToRefresh';
import StravaPausedBanner from '@/components/StravaPausedBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import TrendsSkeleton from '@/components/trends/TrendsSkeleton';
import { useSystemTheme } from '@/hooks/useSystemTheme';
import useTabSkeleton from '@/hooks/useTabSkeleton';
import useViewTransitions from '@/hooks/useViewTransitions';
import { cn } from '@/lib/cn';
import { navTabFor } from '@/lib/nav';

interface AppShellProps {
    children: ReactNode;
}

const TAB_SKELETONS: Readonly<Record<TabId, ComponentType<{ href: string }>>> =
    {
        today: TodaySkeleton,
        plan: PlanSkeleton,
        trends: TrendsSkeleton,
        history: HistorySkeleton,
    };

function TabSkeleton({ tab, href }: Readonly<{ tab: TabId; href: string }>) {
    const Skeleton = TAB_SKELETONS[tab];
    return <Skeleton href={href} />;
}

export default function AppShell({ children }: Readonly<AppShellProps>) {
    useSystemTheme();
    useViewTransitions();
    const { component } = usePage<SharedProps>();
    const hasBottomNav = navTabFor(component) !== null;
    const skeleton = useTabSkeleton(component);

    return (
        <div className="min-h-screen bg-background pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)] text-foreground">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-e2"
            >
                Skip to content
            </a>

            <MobileTopBar />

            {/* No clearance padding: MobileTopBar is in normal flow and
                reserves its own space. */}
            <PullToRefresh>
                <ErrorBanner />
                <FlashNotice />
                <StravaZoneReconnectBanner />
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
                    aria-busy={skeleton !== null || undefined}
                    className={cn(
                        'outline-none',
                        hasBottomNav
                            ? 'pb-[calc(7rem+env(safe-area-inset-bottom))]'
                            : 'pb-[calc(1.75rem+env(safe-area-inset-bottom))]',
                    )}
                >
                    {skeleton !== null && (
                        <TabSkeleton tab={skeleton.tab} href={skeleton.href} />
                    )}
                    <div hidden={skeleton !== null}>{children}</div>
                </main>
            </PullToRefresh>

            <MobileBottomNav />
        </div>
    );
}
