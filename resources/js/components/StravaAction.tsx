import type { ReactNode } from 'react';

import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

/**
 * Gate for a manual Strava affordance. While `stravaPaused` the control is
 * absent rather than disabled, so nothing advertises a pull that would not
 * happen; {@link StravaPausedBanner} carries the one explanation. Connecting is
 * not gated here: OAuth still completes, and it is the only way in.
 */
export default function StravaAction({
    children,
}: Readonly<{ children: ReactNode }>) {
    const paused = usePage<SharedProps>().props.stravaPaused ?? false;

    return paused ? null : <>{children}</>;
}
