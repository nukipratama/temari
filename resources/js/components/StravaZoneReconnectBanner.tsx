import { usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import type { SharedProps } from '@/types/inertia';

/**
 * Surfaces a reconnect nudge when the auth user's Strava connection is live but
 * was granted before the `profile:read_all` scope existed, so HR-zone sync can't
 * run for them yet. Mirrors {@link ErrorBanner}'s placement/shape, mounted once in
 * {@link AppShell}. Static (not dismissable): it should keep nudging until the
 * user reconnects, unlike a one-off flash error.
 */
export default function StravaZoneReconnectBanner() {
    const missing = usePage<SharedProps>().props.stravaZoneScopeMissing ?? false;

    if (!missing) {
        return null;
    }

    return (
        <div className="px-4 pt-4 lg:px-8">
            <div className="mx-auto flex max-w-page-2xl items-start gap-3 rounded-2xl border border-line bg-surface-sunken px-4 py-3">
                <Icon icon="mdi:heart-pulse" width={20} height={20} className="mt-0.5 shrink-0 text-ink-3" aria-hidden />
                <p className="flex-1 font-sans text-sm leading-relaxed text-ink">
                    Sambungin ulang Strava buat sinkronin zona HR kamu otomatis.
                </p>
                <a
                    href="/auth/strava/redirect"
                    className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-strava-orange px-3 py-1.5 font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-white transition hover:bg-strava-orange-hover"
                >
                    <Icon icon="mdi:strava" width={12} height={12} aria-hidden />
                    Sambungin lagi
                </a>
            </div>
        </div>
    );
}
