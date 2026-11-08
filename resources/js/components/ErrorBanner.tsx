import { CircleAlert } from 'lucide-react';
import { Suspense, useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';
import { useSharedProps } from '@/hooks/useSharedProps';
import { lazyIsland } from '@/lib/lazyIsland';

const DemoBlockedModal = lazyIsland(
    () => import('@/components/DemoBlockedModal'),
);

export const DEMO_READ_ONLY_BLOCKED = {
    title: 'the demo is read-only',
    body: 'this is the shared demo, so nothing here can be changed. connect your own Strava and every plan, zone and score is yours to adjust.',
} as const;

/**
 * Surfaces Inertia's shared error bag (Strava-connect denial, demo misconfig,
 * a rejected accessory-equip, etc.) as a dismissable banner. Without it those
 * `withErrors()` redirects bounce the user with no explanation. A demo user's
 * refused write opens the demo read-only modal instead. Mounted once in
 * each shell — {@link AppShell} for the authed app and {@link BareShell} for the
 * standalone screens, which is where the Strava-connect denial lands.
 */
export default function ErrorBanner() {
    const { errors, auth } = useSharedProps();
    const demoBlocked =
        auth.user?.is_demo === true && errors?.demo !== undefined;
    const message =
        Object.entries(errors ?? {}).find(
            ([key]) => !(demoBlocked && key === 'demo'),
        )?.[1] ?? null;
    const [dismissedErrors, setDismissedErrors] = useState<
        SharedProps['errors'] | null
    >(null);

    const dismissed = errors === dismissedErrors;

    return (
        <>
            {message !== null && !dismissed && (
                <Banner
                    tone="error"
                    icon={CircleAlert}
                    onDismiss={() => setDismissedErrors(errors)}
                >
                    {message}
                </Banner>
            )}
            {demoBlocked && (
                <Suspense fallback={null}>
                    <DemoBlockedModal
                        open={!dismissed}
                        onClose={() => setDismissedErrors(errors)}
                        {...DEMO_READ_ONLY_BLOCKED}
                    />
                </Suspense>
            )}
        </>
    );
}
