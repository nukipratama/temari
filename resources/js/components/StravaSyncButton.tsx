import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useState } from 'react';

import type { StravaSyncState } from '@/types/inertia';

import StravaAction from '@/components/StravaAction';
import { cn } from '@/lib/cn';

interface StravaSyncButtonProps {
    state: StravaSyncState;
    /** Extra classes (e.g. a top margin) merged onto the rendered control. */
    className?: string;
}

/**
 * The state-driven Strava call to action shared by the empty states: a connect
 * link when disconnected/revoked, a "Sync now" button when ready, and nothing
 * while a sync is already in flight. The OAuth redirect is a plain `<a>` (full
 * navigation to an external 302), not an Inertia visit.
 *
 * The "Sync now" branch is wrapped in {@link StravaAction}; the connect link is
 * not, since OAuth still completes while the kill-switch is off.
 */
export default function StravaSyncButton({
    state,
    className,
}: Readonly<StravaSyncButtonProps>) {
    const [pending, setPending] = useState(false);

    if (state === 'disconnected' || state === 'revoked') {
        return (
            <a
                href="/auth/strava/redirect"
                className={cn(
                    'inline-flex items-center gap-2 rounded-full bg-strava-orange px-5 py-2.5 text-sm font-semibold text-white hover:bg-strava-orange-hover',
                    className,
                )}
            >
                <Icon icon="mdi:strava" width={16} height={16} aria-hidden />
                {state === 'revoked' ? 'Reconnect' : 'Connect Strava'}
            </a>
        );
    }

    if (state === 'ready') {
        return (
            <StravaAction>
                <button
                    type="button"
                    onClick={() =>
                        router.post(
                            '/strava/sync',
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setPending(true),
                                onFinish: () => setPending(false),
                            },
                        )
                    }
                    disabled={pending}
                    className={cn(
                        'focus-ring inline-flex items-center gap-2 rounded-full border border-cream-deep bg-cream px-5 py-2.5 text-sm font-semibold text-ink-2 transition hover:text-ink disabled:cursor-not-allowed disabled:opacity-60',
                        className,
                    )}
                >
                    <Icon
                        icon={pending ? 'mdi:loading' : 'mdi:sync'}
                        width={16}
                        height={16}
                        aria-hidden
                        className={cn('text-ink-3', pending && 'animate-spin')}
                    />
                    {pending ? 'Syncing…' : 'Sync now'}
                </button>
            </StravaAction>
        );
    }

    return null;
}
