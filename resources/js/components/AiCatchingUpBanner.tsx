import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

/**
 * Calm, app-wide reassurance shown while the auth user has at least one
 * synced activity still waiting on its narration (`aiCatchingUp`) — a
 * backfill chain hasn't reached it yet, or a failed attempt is still under
 * retry budget. Mirrors {@link AiOutageBanner}'s placement/shape, mounted
 * once in {@link AppShell}; static (not dismissable) and action-less. Never
 * shown alongside {@link AiOutageBanner} — the server skips `aiCatchingUp`
 * entirely while generation is globally paused, since that banner already
 * explains it.
 */
export default function AiCatchingUpBanner() {
    const catchingUp = usePage<SharedProps>().props.aiCatchingUp ?? false;

    if (!catchingUp) {
        return null;
    }

    return (
        <div className="px-4 pt-4 lg:px-8">
            <div className="mx-auto flex max-w-page-2xl items-start gap-3 rounded-2xl border border-line bg-surface-sunken px-4 py-3">
                <Icon
                    icon="mdi:progress-clock"
                    width={20}
                    height={20}
                    className="mt-0.5 shrink-0 text-ink-3"
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-ink">
                    Still processing in the background. Check back in a bit, the
                    narration will catch up automatically.
                </p>
            </div>
        </div>
    );
}
