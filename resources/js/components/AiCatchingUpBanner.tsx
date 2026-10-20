import { LoaderCircle } from 'lucide-react';

import Banner from '@/components/ui/Banner';
import { useSharedProps } from '@/hooks/useSharedProps';

/**
 * Calm, app-wide reassurance shown while the auth user has an activity still
 * waiting on its narration (`aiCatchingUp`). Mirrors {@link AiOutageBanner}'s
 * placement, mounted once in {@link AppShell}; static and action-less, and never
 * shown alongside it, since the server skips `aiCatchingUp` while generation is
 * paused.
 */
export default function AiCatchingUpBanner() {
    const catchingUp = useSharedProps().aiCatchingUp ?? false;

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
