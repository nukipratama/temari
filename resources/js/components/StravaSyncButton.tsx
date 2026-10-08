import { router } from '@inertiajs/react';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { Suspense, useState } from 'react';

import type { StravaSyncState } from '@/types/inertia';

import StravaAction from '@/components/StravaAction';
import { Icon, StravaIcon } from '@/components/ui/Icon';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';
import { pillButtonVariants } from '@/lib/variants';

const DemoBlockedModal = lazyIsland(
    () => import('@/components/DemoBlockedModal'),
);

export const SYNC_DEMO_BLOCKED = {
    title: "the demo doesn't sync",
    body: 'this is the shared demo, so its runs are a fixed set. connect your own Strava and sync now pulls in your latest runs.',
} as const;

interface StravaSyncButtonProps {
    state: StravaSyncState;
    /** Extra classes (e.g. a top margin) merged onto the rendered control. */
    className?: string;
}

/**
 * The state-driven Strava call to action shared by the empty states: a connect
 * link when disconnected/revoked, a "Sync now" button when ready or a previous
 * attempt failed, and nothing while a sync is already in flight. The OAuth
 * redirect is a plain `<a>` (full navigation to an external 302), not an
 * Inertia visit.
 *
 * The "Sync now" branch is wrapped in {@link StravaAction}; the connect link is
 * not, since OAuth still completes while the kill-switch is off.
 */
export default function StravaSyncButton({
    state,
    className,
}: Readonly<StravaSyncButtonProps>) {
    const [pending, setPending] = useState(false);
    const {
        isDemo,
        open: demoBlocked,
        setOpen: setDemoBlocked,
        guard,
    } = useDemoGuard();

    if (state === 'disconnected' || state === 'revoked') {
        return (
            <a
                href="/auth/strava/redirect"
                className={cn(
                    pillButtonVariants({ tone: 'outline', size: 'md' }),
                    className,
                )}
            >
                <Icon icon={StravaIcon} width={16} height={16} aria-hidden />
                {state === 'revoked' ? 'reconnect' : 'connect Strava'}
            </a>
        );
    }

    if (state === 'ready' || state === 'failed') {
        return (
            <StravaAction>
                <button
                    type="button"
                    onClick={() =>
                        guard(() =>
                            router.post(
                                '/strava/sync',
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setPending(true),
                                    onFinish: () => setPending(false),
                                },
                            ),
                        )
                    }
                    disabled={pending}
                    className={cn(
                        pillButtonVariants({ tone: 'outline', size: 'md' }),
                        className,
                    )}
                >
                    <Icon
                        icon={pending ? LoaderCircle : RefreshCw}
                        width={16}
                        height={16}
                        aria-hidden
                        className={cn('text-text-3', pending && 'animate-spin')}
                    />
                    {pending ? 'syncing…' : 'sync now'}
                </button>
                {isDemo && (
                    <Suspense fallback={null}>
                        <DemoBlockedModal
                            open={demoBlocked}
                            onClose={() => setDemoBlocked(false)}
                            {...SYNC_DEMO_BLOCKED}
                        />
                    </Suspense>
                )}
            </StravaAction>
        );
    }

    return null;
}
