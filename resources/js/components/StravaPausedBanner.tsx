import { usePage } from '@inertiajs/react';
import { RefreshCwOff } from 'lucide-react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';

/**
 * Calm, app-wide notice shown when the Strava kill-switch is off
 * (`stravaPaused`). Every manual sync affordance hides while it is up, so this
 * is the one place that explains the quiet. Only the pause fact is shared,
 * never the operator-facing reason. Mirrors {@link AiOutageBanner}'s
 * placement/shape, mounted once in {@link AppShell}; static and action-less.
 */
export default function StravaPausedBanner() {
    const paused = usePage<SharedProps>().props.stravaPaused ?? false;

    if (!paused) {
        return null;
    }

    return (
        <Banner icon={RefreshCwOff}>
            the pull from Strava is paused for a bit. your runs are safe on
            Strava, they&apos;ll pull back in automatically.
        </Banner>
    );
}
