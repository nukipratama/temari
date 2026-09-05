import type { ReactNode } from 'react';

import ErrorBanner from '@/components/ErrorBanner';
import { useSwipeBack } from '@/hooks/useSwipeBack';
import { useSystemTheme } from '@/hooks/useSystemTheme';

interface BareShellProps {
    children: ReactNode;
}

export default function BareShell({ children }: Readonly<BareShellProps>) {
    useSwipeBack();
    useSystemTheme();

    return (
        // No MobileTopBar here, so this shell pads the top itself. The inset is
        // 0 under the solid status bar, hence the floor.
        <div className="min-h-screen bg-background pt-[max(1rem,env(safe-area-inset-top))] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)] text-foreground">
            <ErrorBanner />
            {children}
        </div>
    );
}

/**
 * For standalone screens outside the app chrome (Login, the legal documents).
 * Only `ErrorBanner` belongs here — it reports on the request the visitor just
 * made. The AI and Strava pipeline-state banners are `AppShell`'s alone: nothing
 * on a standalone screen is narrated or synced, so they would only ever be noise.
 */
export const bareLayout = (page: ReactNode) => <BareShell>{page}</BareShell>;
