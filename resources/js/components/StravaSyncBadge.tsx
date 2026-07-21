import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatRelativeId } from '@/lib/pace';
import type { StravaSync, StravaSyncState } from '@/types/inertia';

interface StravaSyncBadgeProps {
    sync: StravaSync | null;
    /** `compact` is for the mobile top bar; `normal` is the desktop TopNav size. */
    density?: 'compact' | 'normal';
    /**
     * Flips the chip for a dark ground. The mobile top bar is `sky` (see
     * MobileTopBar), where the default tint-on-cream treatment is invisible.
     */
    onDark?: boolean;
}

export default function StravaSyncBadge({ sync, density = 'normal', onDark = false }: Readonly<StravaSyncBadgeProps>) {
    // Default a missing prop to disconnected so a brief server/client deploy
    // skew never renders a blank badge.
    const state: StravaSyncState = sync?.state ?? 'disconnected';
    const relative = state === 'ready' && sync?.last_synced_at ? formatRelativeId(sync.last_synced_at) : null;
    const isCompact = density === 'compact';

    const { label, ariaLabel, icon, iconClass } = resolveBadge(state, relative, isCompact, onDark);
    const badgeClass = cn(
        'inline-flex items-center whitespace-nowrap rounded-full font-mono font-bold uppercase tracking-[0.1em]',
        onDark ? 'bg-white/10 text-ink-on-sky' : 'bg-sky/[0.06] text-ink-2',
        isCompact ? 'gap-1.5 px-2.5 py-1.5 text-[11px]' : 'gap-2 px-3.5 py-2 text-[11px]',
    );
    const content = (
        <>
            {/* The sync glyph labels the badge as sync freshness, so a bare relative time
                ("19 jam lalu") on the compact top bar can't misread as "last run 19h ago". */}
            <Icon icon={icon} width={13} height={13} aria-hidden className={cn('shrink-0', iconClass)} />
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
                    'transition',
                    onDark ? 'focus-ring-on-sky hover:bg-white/20' : 'focus-ring hover:bg-sky/[0.12]',
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

/**
 * `onDark` picks the base hue over the `-deep` variant for each state: the deep
 * tones are tuned for contrast against cream and go muddy on the sky ground.
 */
function resolveBadge(
    state: StravaSyncState,
    relative: string | null,
    isCompact: boolean,
    onDark: boolean,
): { label: string; ariaLabel: string; icon: string; iconClass: string } {
    switch (state) {
        case 'ready': {
            const full = relative ? `Strava synced · ${relative}` : 'Strava synced';
            return {
                label: isCompact ? (relative ?? 'Synced') : full,
                ariaLabel: relative ? `Strava synced ${relative}` : 'Strava synced',
                icon: 'mdi:cloud-check-variant-outline',
                iconClass: onDark ? 'text-leaf' : 'text-leaf-deep',
            };
        }
        case 'syncing':
            return {
                label: isCompact ? 'Sinkron' : 'Lagi sinkron',
                ariaLabel: 'Strava lagi sinkron',
                icon: 'mdi:sync',
                iconClass: onDark ? 'text-horizon animate-spin' : 'text-horizon-deep animate-spin',
            };
        case 'revoked':
            return {
                label: isCompact ? 'Sambungkan ulang' : 'Strava putus · Sambungkan ulang',
                ariaLabel: 'Sambungan Strava putus, sambungkan ulang',
                icon: 'mdi:cloud-alert-outline',
                iconClass: onDark ? 'text-ember' : 'text-ember-deep',
            };
        default:
            return {
                label: 'Strava',
                ariaLabel: 'Strava belum nyambung',
                icon: 'mdi:cloud-off-outline',
                iconClass: onDark ? 'text-ink-on-sky' : 'text-ink-3',
            };
    }
}
