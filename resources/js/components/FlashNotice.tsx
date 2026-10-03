import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Info } from 'lucide-react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import Banner, { type BannerTone } from '@/components/ui/Banner';
import { type IconComponent } from '@/components/ui/Icon';

type FlashTone = 'error' | 'info' | 'success';

const TONES: Record<FlashTone, { icon: IconComponent; tone: BannerTone }> = {
    error: { icon: CircleAlert, tone: 'error' },
    info: { icon: Info, tone: 'neutral' },
    success: { icon: CircleCheck, tone: 'success' },
};

const ORDER: readonly FlashTone[] = ['error', 'info', 'success'];

/**
 * Surfaces the `flash.error` / `flash.info` / `flash.success` shared props as a
 * dismissable banner. Without it a `back()->with('info', …)` refusal — the
 * honest answer the Strava kill-switch gives instead of a fake success — lands
 * on a page that renders nothing. Mounted once in {@link AppShell}, alongside
 * {@link ErrorBanner}, which does the same job for the `withErrors()` bag.
 */
export default function FlashNotice() {
    const { flash } = usePage<SharedProps>().props;
    const tone =
        ORDER.find(
            (key) => typeof flash?.[key] === 'string' && flash[key] !== '',
        ) ?? null;
    const message = tone === null ? null : (flash?.[tone] ?? null);
    const [dismissed, setDismissed] = useState(false);
    const [lastMessage, setLastMessage] = useState(message);

    // A fresh flash (new message) re-shows the banner after a prior dismissal.
    // Adjusted during render (React-endorsed) rather than in an effect.
    if (message !== lastMessage) {
        setLastMessage(message);
        setDismissed(false);
    }

    if (tone === null || message === null || dismissed) {
        return null;
    }

    const style = TONES[tone];

    return (
        <Banner
            tone={style.tone}
            icon={style.icon}
            role="status"
            onDismiss={() => setDismissed(true)}
        >
            {message}
        </Banner>
    );
}
