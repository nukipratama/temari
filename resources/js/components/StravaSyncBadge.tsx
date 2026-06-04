import { cn } from '@/lib/cn';
import { formatRelativeId } from '@/lib/pace';
import type { StravaSync, StravaSyncState } from '@/types/inertia';

interface StravaSyncBadgeProps {
    sync: StravaSync | null;
    /** `compact` is for the mobile top bar; `normal` is the desktop TopNav size. */
    density?: 'compact' | 'normal';
}

export default function StravaSyncBadge({ sync, density = 'normal' }: Readonly<StravaSyncBadgeProps>) {
    // Default a missing prop to disconnected so a brief server/client deploy
    // skew never renders a blank badge.
    const state: StravaSyncState = sync?.state ?? 'disconnected';
    const relative = state === 'ready' && sync?.last_synced_at ? formatRelativeId(sync.last_synced_at) : null;
    const isCompact = density === 'compact';

    const { label, ariaLabel, dotClass } = resolveBadge(state, relative, isCompact);

    return (
        <span
            aria-label={ariaLabel}
            className={cn(
                'inline-flex items-center rounded-full bg-sky/[0.06] font-mono font-bold uppercase tracking-[0.1em] text-ink-2',
                isCompact ? 'gap-1.5 px-2.5 py-1.5 text-[11px]' : 'gap-2 px-3.5 py-2 text-[11px]',
            )}
        >
            <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', dotClass)} />
            {label}
        </span>
    );
}

function resolveBadge(
    state: StravaSyncState,
    relative: string | null,
    isCompact: boolean,
): { label: string; ariaLabel: string; dotClass: string } {
    switch (state) {
        case 'ready': {
            const full = relative ? `Strava synced · ${relative}` : 'Strava synced';
            return {
                label: isCompact ? (relative ?? 'Synced') : full,
                ariaLabel: relative ? `Strava synced ${relative}` : 'Strava synced',
                dotClass: 'bg-leaf',
            };
        }
        case 'syncing':
            return {
                label: isCompact ? 'Sinkron' : 'Lagi sinkron',
                ariaLabel: 'Strava lagi sinkron',
                dotClass: 'bg-horizon animate-pulse',
            };
        case 'revoked':
            return {
                label: isCompact ? 'Putus' : 'Strava putus',
                ariaLabel: 'Sambungan Strava putus',
                dotClass: 'bg-ember',
            };
        default:
            return { label: 'Strava', ariaLabel: 'Strava belum nyambung', dotClass: 'bg-ink-3/40' };
    }
}
