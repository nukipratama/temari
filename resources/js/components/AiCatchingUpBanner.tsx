import { usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';

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
        <Banner icon={LoaderCircle}>
            temari&apos;s still reading through your runs. check back in a bit,
            your notes will catch up on their own.
        </Banner>
    );
}
