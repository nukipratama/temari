import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

/**
 * Calm, app-wide notice shown when the Strava kill-switch is off
 * (`stravaPaused`). Every manual sync affordance hides while it is up, so this
 * is the one place that explains the quiet. Only the pause fact is shared,
 * never the operator-facing reason. Mirrors {@link AiOutageBanner}'s
 * placement/shape, mounted once in {@link AppShell}; static and action-less.
 */
export default function StravaPausedBanner() {
    const paused = usePage<SharedProps>().props.stravaPaused ?? false;

    if (!paused) {
        return null;
    }

    return (
        <div className="px-4 pt-4 lg:px-8">
            <div className="mx-auto flex max-w-page-2xl items-start gap-3 rounded-2xl border border-line bg-surface-sunken px-4 py-3">
                <Icon
                    icon="mdi:sync-off"
                    width={20}
                    height={20}
                    className="mt-0.5 shrink-0 text-ink-3"
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-ink">
                    The pull from Strava is paused for a bit. Your runs are safe
                    on Strava, they&apos;ll pull back in automatically.
                </p>
            </div>
        </div>
    );
}
