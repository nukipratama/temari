import type { ReactNode } from 'react';

import { VIEWPORTS, type ViewportKey } from '@/components/rack/viewports';
import { cn } from '@/lib/utils';

/**
 * Same device-frame chrome as resources/brand/*-redesign.html's .phone/
 * .island/.browser-bar — mobile/SE keep the notch bezel, tablet drops the
 * island, desktop/wide swap to a plain browser-window chrome.
 */
export function PhoneFrame({
    viewport,
    theme,
    topbar,
    bottomnav,
    children,
}: Readonly<{
    viewport: ViewportKey;
    theme: 'light' | 'dark' | 'system';
    topbar?: ReactNode;
    bottomnav?: ReactNode;
    children: ReactNode;
}>) {
    const vp = VIEWPORTS[viewport];
    const isBrowser = vp.chrome === 'browser';
    const isTablet = vp.chrome === 'tablet';

    return (
        <div
            className={cn(
                'relative bg-[#0d0d0f] shadow-[0_12px_28px_rgba(58,45,20,.12),0_4px_10px_rgba(58,45,20,.07),0_30px_60px_rgba(0,0,0,.25)] transition-[width,height] duration-200',
                isBrowser ? 'rounded-2xl p-0' : 'rounded-[52px] p-3.5',
            )}
            style={{ width: vp.w, height: vp.h }}
        >
            {!isBrowser && !isTablet && (
                <div className="absolute top-[26px] left-1/2 z-20 h-[30px] w-[120px] -translate-x-1/2 rounded-full bg-[#0d0d0f]" />
            )}
            {isBrowser && (
                <div className="flex h-[38px] items-center gap-1.5 rounded-t-2xl bg-[#1c1c1f] px-3.5">
                    <span className="size-2.5 rounded-full bg-[#4a4a50]" />
                    <span className="size-2.5 rounded-full bg-[#4a4a50]" />
                    <span className="size-2.5 rounded-full bg-[#4a4a50]" />
                </div>
            )}
            <div
                data-theme={theme}
                className={cn(
                    'relative overflow-hidden bg-background text-foreground [container-type:inline-size]',
                    isBrowser
                        ? 'h-[calc(100%-38px)] rounded-b-lg'
                        : 'h-full rounded-[38px]',
                )}
            >
                {topbar}
                <div className="h-full overflow-x-hidden overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {children}
                </div>
                {bottomnav}
            </div>
        </div>
    );
}
