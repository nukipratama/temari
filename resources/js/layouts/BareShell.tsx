import type { ReactNode } from 'react';

import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import AiOutageBanner from '@/components/AiOutageBanner';
import ErrorBanner from '@/components/ErrorBanner';
import StravaPausedBanner from '@/components/StravaPausedBanner';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import { useDawnShift } from '@/hooks/useDawnShift';
import { useSwipeBack } from '@/hooks/useSwipeBack';

interface BareShellProps {
    children: ReactNode;
}

export default function BareShell({ children }: Readonly<BareShellProps>) {
    useDawnShift();
    useSwipeBack();

    return (
        // No MobileTopBar here, so this shell pads for the notch itself.
        <div className="min-h-screen bg-cream-deep pt-[env(safe-area-inset-top)] text-ink">
            <ErrorBanner />
            <StravaZoneReconnectBanner />
            <AiOutageBanner />
            <AiCatchingUpBanner />
            <StravaPausedBanner />
            {children}
        </div>
    );
}

/** For standalone screens outside the app chrome (Login). */
export const bareLayout = (page: ReactNode) => <BareShell>{page}</BareShell>;
