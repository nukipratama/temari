import { router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import type { StravaSyncState } from '@/types/inertia';

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
 */
export default function StravaSyncButton({ state, className }: Readonly<StravaSyncButtonProps>) {
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
                {state === 'revoked' ? 'Sambungin lagi' : 'Sambungin Strava'}
            </a>
        );
    }

    if (state === 'ready') {
        return (
            <button
                type="button"
                onClick={() => router.post('/strava/sync', {}, { preserveScroll: true })}
                className={cn(
                    'focus-ring inline-flex items-center gap-2 rounded-full border border-cream-deep bg-cream px-5 py-2.5 text-sm font-semibold text-ink-2 transition hover:text-ink',
                    className,
                )}
            >
                <Icon icon="mdi:sync" width={16} height={16} aria-hidden className="text-ink-3" />
                Sync sekarang
            </button>
        );
    }

    return null;
}
