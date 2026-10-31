import type { ComponentType, ReactNode } from 'react';

import { usePage } from '@inertiajs/react';

import type { TabId } from '@/lib/nav';
import type { PendingTab } from '@/lib/pendingTab';
import type { SharedProps } from '@/types/inertia';

import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import ErrorBanner from '@/components/ErrorBanner';
import FlashNotice from '@/components/FlashNotice';
import HistorySkeleton from '@/components/history/HistorySkeleton';
import TodaySkeleton from '@/components/home/TodaySkeleton';
import InboxSkeleton from '@/components/inbox/InboxSkeleton';
import MobileBottomNav from '@/components/MobileBottomNav';
import MobileTopBar from '@/components/MobileTopBar';
import PlanSkeleton from '@/components/plan/PlanSkeleton';
import ProfileSkeleton from '@/components/profile/ProfileSkeleton';
import PullToRefresh from '@/components/PullToRefresh';
import RaceSkeleton from '@/components/race/RaceSkeleton';
import RunShowSkeleton from '@/components/run/RunShowSkeleton';
import SettingsSkeleton from '@/components/settings/SettingsSkeleton';
import StravaPausedBanner from '@/components/StravaPausedBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import TrendsSkeleton from '@/components/trends/TrendsSkeleton';
import Toaster from '@/components/ui/Toast';
import { useSystemTheme } from '@/hooks/useSystemTheme';
import useTabSkeleton from '@/hooks/useTabSkeleton';
import useViewTransitions from '@/hooks/useViewTransitions';
import { cn } from '@/lib/cn';
import { navTabFor } from '@/lib/nav';
import { RACE_PATH } from '@/lib/navRoutes';

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

const PUSHED_SKELETONS: Readonly<
    Record<string, ComponentType<{ effortSaved: boolean | null }>>
> = {
    'Runs/Show': RunShowSkeleton,
    Inbox: InboxSkeleton,
    Profile: ProfileSkeleton,
    'Settings/Index': SettingsSkeleton,
};

function ScreenSkeleton({ pending }: Readonly<{ pending: PendingTab }>) {
    if (pending.screen !== null) {
        const Pushed = PUSHED_SKELETONS[pending.screen];
        return Pushed === undefined ? null : (
            <Pushed effortSaved={pending.effortSaved} />
        );
    }

    const Skeleton =
        pending.href.split('?')[0] === RACE_PATH
            ? RaceSkeleton
            : TAB_SKELETONS[pending.tab];
    return <Skeleton href={pending.href} />;
}

export default function AppShell({ children }: Readonly<AppShellProps>) {
    useSystemTheme();
    const { component } = usePage<SharedProps>();
    useViewTransitions(component);
    const skeleton = useTabSkeleton(component);
    const hasBottomNav =
        skeleton === null
            ? navTabFor(component) !== null
            : skeleton.tab !== null;

    return (
        <div className="min-h-screen bg-background pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)] text-foreground">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-leaf-deep focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-on-fill focus:shadow-e2"
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

                {/* Deliberately unkeyed and unanimated: Inertia already swaps a
                    different component type on a real navigation, so React
                    remounts what it needs to without help. */}
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
                    {skeleton !== null && <ScreenSkeleton pending={skeleton} />}
                    <div hidden={skeleton !== null}>{children}</div>
                </main>
            </PullToRefresh>

            <MobileBottomNav />
            <Toaster aboveNav={hasBottomNav} />
        </div>
    );
}
