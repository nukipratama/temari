import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';

const DISMISS_KEY_PREFIX = 'strava-zone-reconnect-dismissed';

function dismissKeyFor(userId: number | null): string {
    return `${DISMISS_KEY_PREFIX}:${userId ?? 'guest'}`;
}

function readDismissed(key: string): boolean {
    try {
        return window.sessionStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

function rememberDismissed(key: string): void {
    try {
        window.sessionStorage.setItem(key, '1');
    } catch {
        return;
    }
}

/**
 * Surfaces a reconnect nudge when the auth user's Strava connection is live but
 * was granted before the `profile:read_all` scope existed, so HR-zone sync can't
 * run for them yet. Mirrors {@link ErrorBanner}'s placement/shape, mounted once in
 * {@link AppShell}. Dismissable for the browsing session only, keyed per user in
 * `sessionStorage`: it stops nagging on this visit and comes back on the next
 * one, until the scope is actually granted.
 */
export default function StravaZoneReconnectBanner() {
    const { props } = usePage<SharedProps>();
    const missing = props.stravaZoneScopeMissing ?? false;
    const key = dismissKeyFor(props.auth.user?.id ?? null);
    const [dismissed, setDismissed] = useState(() => readDismissed(key));

    if (!missing || dismissed) {
        return null;
    }

    return (
        <div className="px-4 pt-4 min-[900px]:px-6">
            <div className="mx-auto flex max-w-column min-[1280px]:max-w-column-wide items-start gap-3 rounded-lg border border-border bg-muted px-4 py-3">
                <Icon
                    icon="mdi:heart-pulse"
                    width={20}
                    height={20}
                    className="mt-0.5 shrink-0 text-text-3"
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-foreground">
                    Strava only shares your HR zones with the profile scope,
                    which this connection is missing, so anything zone-based
                    falls back to estimates until you reconnect.
                </p>
                <a
                    href="/auth/strava/redirect?from=/profile"
                    className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-strava-orange px-3 py-1.5 font-sans text-[1.1875rem] leading-none font-bold text-white transition hover:bg-strava-orange-hover"
                >
                    <Icon
                        icon="mdi:strava"
                        width={18}
                        height={18}
                        aria-hidden
                    />
                    reconnect
                </a>
                <button
                    type="button"
                    onClick={() => {
                        rememberDismissed(key);
                        setDismissed(true);
                    }}
                    aria-label="Dismiss"
                    className="focus-ring -m-1 shrink-0 rounded p-1 text-text-3 transition hover:text-foreground"
                >
                    <Icon icon="mdi:close" width={16} height={16} />
                </button>
            </div>
        </div>
    );
}
