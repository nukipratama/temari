import { usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';

/**
 * Surfaces Inertia's shared error bag (Strava-connect denial, demo misconfig,
 * a rejected accessory-equip, etc.) as a dismissable banner. Without it those
 * `withErrors()` redirects bounce the user with no explanation. Mounted once in
 * each shell — {@link AppShell} for the authed app and {@link BareShell} for the
 * standalone screens, which is where the Strava-connect denial lands.
 */
export default function ErrorBanner() {
    const errors = usePage<SharedProps>().props.errors;
    const message = Object.values(errors ?? {})[0] ?? null;
    const [dismissedErrors, setDismissedErrors] = useState<
        SharedProps['errors'] | null
    >(null);

    if (message === null || errors === dismissedErrors) {
        return null;
    }

    return (
        <Banner
            tone="error"
            icon={CircleAlert}
            onDismiss={() => setDismissedErrors(errors)}
        >
            {message}
        </Banner>
    );
}
