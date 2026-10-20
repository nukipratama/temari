import { Moon } from 'lucide-react';

import Banner from '@/components/ui/Banner';
import { useSharedProps } from '@/hooks/useSharedProps';

/**
 * Calm reassurance shown when LLM narration is globally paused (`aiPaused`), so
 * a quiet pipeline reads as "Temari is resting" instead of broken-looking empty
 * states. Only the pause fact is shared, never the operator-facing reason.
 * Mirrors {@link StravaZoneReconnectBanner}'s shape, but each page that renders
 * narration blocks mounts it above its content; a page without narration never
 * shows it. Static and action-less.
 */
export default function AiOutageBanner() {
    const paused = useSharedProps().aiPaused ?? false;

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
