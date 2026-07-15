import { usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import type { SharedProps } from '@/types/inertia';

/**
 * Calm, app-wide reassurance shown when LLM narration is globally paused
 * (`aiPaused`), so a quiet pipeline reads as "Temari is resting" instead of a
 * screen of broken-looking empty states. Only the pause fact is shared, never
 * the operator-facing reason, so the copy stays soft and non-diagnostic. Mirrors
 * {@link StravaZoneReconnectBanner}'s placement/shape, mounted once in
 * {@link AppShell}; static (not dismissable) and action-less, this is a friendly
 * heads-up, not an error.
 */
export default function AiOutageBanner() {
    const paused = usePage<SharedProps>().props.aiPaused ?? false;

    if (!paused) {
        return null;
    }

    return (
        <div className="px-4 pt-4 lg:px-8">
            <div className="mx-auto flex max-w-page-2xl items-start gap-3 rounded-2xl border border-line bg-surface-sunken px-4 py-3">
                <Icon icon="mdi:sleep" width={20} height={20} className="mt-0.5 shrink-0 text-ink-3" aria-hidden />
                <p className="flex-1 font-sans text-sm leading-relaxed text-ink">
                    Temari lagi istirahat sebentar. Narasinya nggak ilang kok, nyusul otomatis pas dia balik.
                </p>
            </div>
        </div>
    );
}
