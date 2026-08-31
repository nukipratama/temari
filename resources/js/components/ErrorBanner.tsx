import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';

/**
 * Surfaces Inertia's shared error bag (Strava-connect denial, demo misconfig,
 * a rejected accessory-equip, etc.) as a dismissable banner. Without it those
 * `withErrors()` redirects bounce the user with no explanation. Mounted once in
 * each shell — {@link AppShell} for the authed app and {@link BareShell} for the
 * standalone screens, which is where the Strava-connect denial lands.
 */
export default function ErrorBanner() {
    const errors = usePage<SharedProps>().props.errors ?? {};
    const message = Object.values(errors)[0] ?? null;
    const [dismissed, setDismissed] = useState(false);
    const [lastMessage, setLastMessage] = useState(message);

    // A fresh error (new message) re-shows the banner after a prior dismissal.
    // Adjusted during render (React-endorsed) rather than in an effect.
    if (message !== lastMessage) {
        setLastMessage(message);
        setDismissed(false);
    }

    if (message === null || dismissed) {
        return null;
    }

    return (
        <div className="px-4 pt-4 min-[900px]:px-6">
            <div
                role="alert"
                className="mx-auto flex max-w-[760px] items-start gap-3 rounded-lg border border-ember/30 bg-ember/[0.08] px-4 py-3"
            >
                <Icon
                    icon="mdi:alert-circle-outline"
                    width={20}
                    height={20}
                    className="mt-0.5 shrink-0 text-ember-ink"
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-foreground">
                    {message}
                </p>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    aria-label="Close"
                    className="focus-ring -m-1 rounded p-1 text-text-3 transition hover:text-foreground"
                >
                    <Icon icon="mdi:close" width={16} height={16} />
                </button>
            </div>
        </div>
    );
}
