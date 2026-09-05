import { useEffect, useState } from 'react';

/**
 * Temporary on-device readout for the installed-app top strip. Four attempts to
 * fix that region were reasoned from the spec and four were wrong, because a
 * Safari install and a Chrome install disagree about who owns it and neither is
 * reproducible on desktop. Reachable only via `?viewport-debug` so it costs
 * nothing to leave in place until the strip is settled; delete it after.
 */
function readout(): Record<string, string> {
    const probe = document.createElement('div');
    probe.style.cssText =
        'position:fixed;top:0;height:env(safe-area-inset-top);width:env(safe-area-inset-bottom)';
    document.body.appendChild(probe);
    const style = getComputedStyle(probe);
    const insetTop = style.height;
    const insetBottom = style.width;
    probe.remove();

    const scrim = document.querySelector('[data-testid="status-bar-scrim"]');
    const rect = scrim?.getBoundingClientRect();

    return {
        'inset-top': insetTop,
        'inset-bottom': insetBottom,
        scrim: rect
            ? `${Math.round(rect.height)}px @ y=${Math.round(rect.top)}`
            : 'not mounted',
        viewport: `${window.innerWidth}x${window.innerHeight} @${window.devicePixelRatio}x`,
        screen: `${window.screen.width}x${window.screen.height}`,
        visual: window.visualViewport
            ? `${Math.round(window.visualViewport.height)} off=${Math.round(window.visualViewport.offsetTop)}`
            : 'none',
        standalone: String(
            window.matchMedia('(display-mode: standalone)').matches,
        ),
        'nav.standalone': String(
            (window.navigator as { standalone?: boolean }).standalone ??
                'undef',
        ),
    };
}

export default function ViewportDebug() {
    const [rows, setRows] = useState<Record<string, string> | null>(null);

    useEffect(() => {
        if (!window.location.search.includes('viewport-debug')) {
            return;
        }
        // Polled rather than read once: the insets settle a frame or two after
        // a cold standalone launch, and rotating the device changes them.
        const id = window.setInterval(() => setRows(readout()), 300);
        return () => window.clearInterval(id);
    }, []);

    if (rows === null) {
        return null;
    }

    return (
        <div className="fixed inset-x-2 bottom-2 z-[999] rounded-lg border border-border bg-card p-3 font-mono text-label-micro leading-tight text-foreground">
            {Object.entries(rows).map(([key, value]) => (
                <div key={key}>
                    {key}: {value}
                </div>
            ))}
        </div>
    );
}
