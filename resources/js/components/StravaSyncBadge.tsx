import { cn } from '@/lib/cn';
import { formatRelativeId } from '@/lib/pace';
import type { StravaSync } from '@/types/inertia';

interface StravaSyncBadgeProps {
    sync: StravaSync | null;
    /** `compact` is for the mobile top bar; `normal` is the desktop TopNav size. */
    density?: 'compact' | 'normal';
}

export default function StravaSyncBadge({ sync, density = 'normal' }: Readonly<StravaSyncBadgeProps>) {
    const connected = sync !== null && sync.connected;
    const relative = connected && sync.last_synced_at ? formatRelativeId(sync.last_synced_at) : null;
    const isCompact = density === 'compact';

    const syncedAriaLabel = relative ? `Strava synced ${relative}` : 'Strava synced';
    const ariaLabel = connected ? syncedAriaLabel : 'Strava belum nyambung';

    let label: string;
    if (!connected) {
        label = 'Strava';
    } else if (isCompact) {
        label = relative ?? 'Synced';
    } else {
        label = relative ? `Strava synced · ${relative}` : 'Strava synced';
    }

    return (
        <span
            aria-label={ariaLabel}
            className={cn(
                'inline-flex items-center rounded-full bg-sky/[0.06] font-mono font-bold uppercase tracking-[0.1em] text-ink-2',
                isCompact ? 'gap-1.5 px-2.5 py-1.5 text-[11px]' : 'gap-2 px-3.5 py-2 text-[11px]',
            )}
        >
            <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', connected ? 'bg-leaf' : 'bg-ink-3/40')} />
            {label}
        </span>
    );
}
