import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';

type FlashTone = 'error' | 'info' | 'success';

const TONES: Record<
    FlashTone,
    { icon: string; role: 'alert' | 'status'; frame: string; glyph: string }
> = {
    error: {
        icon: 'mdi:alert-circle-outline',
        role: 'alert',
        frame: 'border-ember/30 bg-ember/[0.08]',
        glyph: 'text-ember-ink',
    },
    info: {
        icon: 'mdi:information-outline',
        role: 'status',
        frame: 'border-border bg-popover',
        glyph: 'text-text-3',
    },
    success: {
        icon: 'mdi:check-circle-outline',
        role: 'status',
        frame: 'border-leaf/30 bg-leaf/[0.08]',
        glyph: 'text-leaf-ink',
    },
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
        <div className="px-4 pt-4 lg:px-8">
            <div
                role={style.role}
                className={`mx-auto flex max-w-page-2xl items-start gap-3 rounded-lg border px-4 py-3 ${style.frame}`}
            >
                <Icon
                    icon={style.icon}
                    width={20}
                    height={20}
                    className={`mt-0.5 shrink-0 ${style.glyph}`}
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-foreground">
                    {message}
                </p>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    aria-label="Close"
                    className="focus-ring -m-1 rounded p-1 text-text-3 transition hover:text-foreground"
                >
                    <Icon icon="mdi:close" width={16} height={16} />
                </button>
            </div>
        </div>
    );
}
