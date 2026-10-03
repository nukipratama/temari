import type { ReactNode } from 'react';

import { useSharedProps } from '@/hooks/useSharedProps';

/**
 * Gate for a manual Strava affordance. While `stravaPaused` the control is
 * absent rather than disabled, so nothing advertises a pull that would not
 * happen; {@link StravaPausedBanner} carries the one explanation. Connecting is
 * not gated here: OAuth still completes, and it is the only way in.
 */
export default function StravaAction({
    children,
}: Readonly<{ children: ReactNode }>) {
    const paused = useSharedProps().stravaPaused ?? false;

    return paused ? null : <>{children}</>;
}
