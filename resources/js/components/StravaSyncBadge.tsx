import { Icon } from '@iconify/react';

import type { StravaSync, StravaSyncState } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { formatRelativeId } from '@/lib/pace';

interface StravaSyncBadgeProps {
    sync: StravaSync | null;
    /** `compact` is for the mobile top bar; `normal` is the desktop TopNav size. */
    density?: 'compact' | 'normal';
}

export default function StravaSyncBadge({
    sync,
    density = 'normal',
}: Readonly<StravaSyncBadgeProps>) {
    // Default a missing prop to disconnected so a brief server/client deploy
    // skew never renders a blank badge.
    const state: StravaSyncState = sync?.state ?? 'disconnected';
    const relative =
        state === 'ready' && sync?.last_synced_at
            ? formatRelativeId(sync.last_synced_at)
            : null;
    const isCompact = density === 'compact';

    const { label, ariaLabel, icon, iconClass } = resolveBadge(
        state,
        relative,
        isCompact,
    );
    const badgeClass = cn(
        'inline-flex items-center whitespace-nowrap rounded-full bg-sky/[0.06] text-label-micro text-ink-2',
        isCompact ? 'gap-1.5 px-2.5 py-1.5' : 'gap-2 px-3.5 py-2',
    );
    const content = (
        <>
            {/* The sync glyph labels the badge as sync freshness, so a bare relative time
                ("19h ago") on the compact top bar can't misread as "last run 19h ago". */}
            <Icon
                icon={icon}
                width={13}
                height={13}
                aria-hidden
                className={cn('shrink-0', iconClass)}
            />
            {label}
        </>
    );

    // Revoked is the only state with an obvious fix, so the badge itself becomes
    // the reconnect affordance instead of staying an inert status readout.
    if (state === 'revoked') {
        return (
            <a
                href="/auth/strava/redirect"
                aria-label={ariaLabel}
                className={cn(
                    badgeClass,
                    'focus-ring transition hover:bg-sky/[0.12]',
                )}
            >
                {content}
            </a>
        );
    }

    return (
        <span aria-label={ariaLabel} className={badgeClass}>
            {content}
        </span>
    );
}

function resolveBadge(
    state: StravaSyncState,
    relative: string | null,
    isCompact: boolean,
): { label: string; ariaLabel: string; icon: string; iconClass: string } {
    switch (state) {
        case 'ready': {
            const full = relative
                ? `Strava synced · ${relative}`
                : 'Strava synced';
            return {
                label: isCompact ? (relative ?? 'Synced') : full,
                ariaLabel: relative
                    ? `Strava synced ${relative}`
                    : 'Strava synced',
                icon: 'mdi:cloud-check-variant-outline',
                iconClass: 'text-leaf-deep',
            };
        }
        case 'syncing':
            return {
                label: isCompact ? 'Sync' : 'Syncing',
                ariaLabel: 'Strava syncing',
                icon: 'mdi:sync',
                iconClass: 'text-horizon-ink animate-spin',
            };
        case 'revoked':
            return {
                label: isCompact
                    ? 'Reconnect'
                    : 'Strava disconnected · Reconnect',
                ariaLabel: 'Strava connection lost, reconnect',
                icon: 'mdi:cloud-alert-outline',
                iconClass: 'text-ember-deep',
            };
        default:
            return {
                label: 'Strava',
                ariaLabel: 'Strava not connected',
                icon: 'mdi:cloud-off-outline',
                iconClass: 'text-ink-3',
            };
    }
}
