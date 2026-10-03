import { usePage } from '@inertiajs/react';
import { Moon } from 'lucide-react';

import type { SharedProps } from '@/types/inertia';

import Banner from '@/components/ui/Banner';

/**
 * Calm reassurance shown when LLM narration is globally paused (`aiPaused`), so
 * a quiet pipeline reads as "Temari is resting" instead of a screen of
 * broken-looking empty states. Only the pause fact is shared, never the
 * operator-facing reason, so the copy stays soft and non-diagnostic. Mirrors
 * {@link StravaZoneReconnectBanner}'s shape, but each page that renders
 * narration blocks mounts it above its content, and a page without narration
 * never shows it; its test derives that set from the pages' imports. Static
 * (not dismissable) and action-less, this is a friendly heads-up, not an error.
 */
export default function AiOutageBanner() {
    const paused = usePage<SharedProps>().props.aiPaused ?? false;

    if (!paused) {
        return null;
    }

    return (
        <Banner icon={Moon}>
            temari&apos;s catching her breath. your notes aren&apos;t lost,
            they&apos;ll catch up on their own.
        </Banner>
    );
}
