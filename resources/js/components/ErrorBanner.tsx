import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from '@iconify/react';
import type { SharedProps } from '@/types/inertia';

/**
 * Surfaces Inertia's shared error bag (Strava-connect denial, demo misconfig,
 * a rejected accessory-equip, etc.) as a dismissable banner. Without it those
 * `withErrors()` redirects bounce the user with no explanation. Mounted once in
 * {@link AppShell} so it covers both the guest login page and the authed app.
 */
export default function ErrorBanner() {
    const errors = usePage<SharedProps>().props.errors ?? {};
    const message = Object.values(errors)[0] ?? null;
    const [dismissed, setDismissed] = useState(false);

    // A fresh error (new message) re-shows the banner after a prior dismissal.
    useEffect(() => {
        setDismissed(false);
    }, [message]);

    if (message === null || dismissed) {
        return null;
    }

    return (
        <div className="px-4 pt-4 lg:px-8">
            <div
                role="alert"
                className="mx-auto flex max-w-page-2xl items-start gap-3 rounded-2xl border border-ember/30 bg-ember/[0.08] px-4 py-3"
            >
                <Icon icon="mdi:alert-circle-outline" width={20} height={20} className="mt-0.5 shrink-0 text-ember-deep" aria-hidden />
                <p className="flex-1 font-sans text-sm leading-relaxed text-ink">{message}</p>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    aria-label="Tutup"
                    className="focus-ring -m-1 rounded p-1 text-ink-3 transition hover:text-ink"
                >
                    <Icon icon="mdi:close" width={16} height={16} />
                </button>
            </div>
        </div>
    );
}
