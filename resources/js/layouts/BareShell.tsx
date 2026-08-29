import type { ReactNode } from 'react';

import ErrorBanner from '@/components/ErrorBanner';
import { useDawnShift } from '@/hooks/useDawnShift';
import { useSwipeBack } from '@/hooks/useSwipeBack';
import { useSystemTheme } from '@/hooks/useSystemTheme';

interface BareShellProps {
    children: ReactNode;
}

export default function BareShell({ children }: Readonly<BareShellProps>) {
    useDawnShift();
    useSwipeBack();
    useSystemTheme();

    return (
        // No MobileTopBar here, so this shell pads for the notch itself.
        <div className="min-h-screen bg-background pt-[env(safe-area-inset-top)] text-foreground">
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
