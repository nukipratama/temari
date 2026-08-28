import { useState, type ReactNode } from 'react';

import { PhoneFrame } from '@/components/rack/PhoneFrame';
import { VIEWPORTS, type ViewportKey } from '@/components/rack/viewports';
import { cn } from '@/lib/utils';

const THEMES = [
    {
        key: 'light',
        label: 'Light',
        caption: 'Forced via data-theme="light" — the default palette.',
    },
    {
        key: 'dark',
        label: 'Dark',
        caption:
            'Forced via data-theme="dark" — Sky family as ground, Cream as text.',
    },
    {
        key: 'system',
        label: 'System',
        caption:
            'No forced theme — follows your OS/browser prefers-color-scheme live.',
    },
] as const;

/**
 * Same review-rack pattern as the static HTML mockups this prototype ported
 * from: a viewport switcher resizing 3 side-by-side theme frames (light/dark/system) at once.
 * `render` gets the active theme so a page can vary its content per frame
 * (e.g. a state demo open in one column only).
 */
export function Rack({
    render,
    topbar,
    bottomnav,
}: Readonly<{
    render: (theme: 'light' | 'dark' | 'system') => ReactNode;
    topbar?: ReactNode;
    bottomnav?: ReactNode;
}>) {
    const [viewport, setViewport] = useState<ViewportKey>('mobile');

    return (
        <div>
            <div className="mb-7 flex flex-wrap gap-2">
                {Object.values(VIEWPORTS).map((vp) => (
                    <button
                        key={vp.key}
                        type="button"
                        onClick={() => setViewport(vp.key)}
                        className={cn(
                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                            viewport === vp.key
                                ? 'border-ink bg-ink text-cream'
                                : 'border-line-strong bg-white text-ink-2',
                        )}
                    >
                        {vp.label}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-start gap-10">
                {THEMES.map((t) => (
                    <div key={t.key} className="flex flex-col items-start">
                        <PhoneFrame
                            viewport={viewport}
                            theme={t.key}
                            topbar={topbar}
                            bottomnav={bottomnav}
                        >
                            {render(t.key)}
                        </PhoneFrame>
                        <div
                            className="mt-3 text-xs"
                            style={{ width: VIEWPORTS[viewport].w }}
                        >
                            <b className="block text-[13px] font-bold">
                                {t.label}
                            </b>
                            <span className="text-[#6a6a72]">{t.caption}</span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
