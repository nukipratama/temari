import { type ReactNode, useRef } from 'react';

import { OverlayContainerContext } from '@/components/ui/Overlay';
import { cn } from '@/lib/cn';

const GROUNDS = ['light', 'dark'] as const;

/** One example on both grounds side by side; the light half relies on the catalogue pinning the document to light. */
export default function GroundFrame({
    overlay = false,
    children,
}: Readonly<{ overlay?: boolean; children: ReactNode }>) {
    return (
        <div className="grid gap-2 md:grid-cols-2">
            {GROUNDS.map((ground) => (
                <Ground key={ground} ground={ground} overlay={overlay}>
                    {children}
                </Ground>
            ))}
        </div>
    );
}

function Ground({
    ground,
    overlay,
    children,
}: Readonly<{
    ground: (typeof GROUNDS)[number];
    overlay: boolean;
    children: ReactNode;
}>) {
    const slotRef = useRef<HTMLDivElement>(null);

    return (
        <div
            data-ground={ground}
            data-theme={ground === 'dark' ? 'dark' : undefined}
            className="min-w-0 rounded-md border border-border bg-background text-foreground"
        >
            <div className="px-3 pt-2 text-label-micro text-text-3">
                {ground}
            </div>
            <div
                ref={slotRef}
                className={cn(
                    "relative min-w-0 p-4 empty:after:text-meta empty:after:content-['renders_nothing']",
                    overlay && 'h-[34rem] transform-gpu overflow-hidden',
                )}
            >
                <OverlayContainerContext value={slotRef}>
                    {children}
                </OverlayContainerContext>
            </div>
        </div>
    );
}
