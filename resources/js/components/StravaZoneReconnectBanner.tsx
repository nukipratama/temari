import { usePage } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';
import { Icon, StravaIcon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { pillButtonVariants } from '@/lib/variants';

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
        <Banner
            icon={HeartPulse}
            action={
                <a
                    href="/auth/strava/redirect?from=/profile"
                    className={cn(
                        pillButtonVariants({ tone: 'outline', size: 'sm' }),
                        'shrink-0',
                    )}
                >
                    <Icon
                        icon={StravaIcon}
                        width={18}
                        height={18}
                        aria-hidden
                    />
                    reconnect
                </a>
            }
            onDismiss={() => {
                rememberDismissed(key);
                setDismissed(true);
            }}
        >
            Strava only shares your HR zones with the profile scope, which this
            connection is missing, so anything zone-based falls back to
            estimates until you reconnect.
        </Banner>
    );
}
