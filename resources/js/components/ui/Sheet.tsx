import { type ReactNode, useRef, useState } from 'react';

import Overlay, { OverlayClose, OverlayTitle } from '@/components/ui/Overlay';
import { cn } from '@/lib/cn';

/** How far down the sheet must travel before letting go dismisses it. */
export const SWIPE_DISMISS_PX = 80;

/**
 * A bottom sheet on {@link Overlay}, which owns the focus trap, the body
 * scroll lock, Back and the escape/outside-press dismissals. Everything on top of it
 * is CSS: the slide-up is a transition keyed off Base UI's own
 * `data-starting-style` / `data-ending-style`, not framer-motion, so the sheet
 * stays usable from the bare layout whose entry chunk carries no motion
 * library.
 */
export default function Sheet({
    open,
    onOpenChange,
    title,
    lead,
    centeredFromMd = false,
    className,
    children,
}: Readonly<{
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** Rendered above the title. */
    lead?: ReactNode;
    /** From `md` up, a centered card that fades in, with no grip. */
    centeredFromMd?: boolean;
    className?: string;
    children: ReactNode;
}>) {
    const startYRef = useRef<number | null>(null);
    const [dragY, setDragY] = useState<number | null>(null);

    const endDrag = (dismiss: boolean) => {
        if (startYRef.current === null) {
            return;
        }
        startYRef.current = null;
        if (dismiss && (dragY ?? 0) >= SWIPE_DISMISS_PX) {
            onOpenChange(false);
        }
        setDragY(null);
    };

    return (
        <Overlay
            open={open}
            onOpenChange={onOpenChange}
            backdropProps={{
                'data-testid': 'sheet-scrim',
                className:
                    'fixed inset-0 z-40 bg-sky/60 transition-opacity duration-200 data-[ending-style]:opacity-0 data-[starting-style]:opacity-0',
            }}
            className={cn(
                'fixed inset-x-0 bottom-0 z-50 mx-auto flex max-h-[85svh] w-full max-w-lg flex-col overflow-y-auto rounded-t-4xl bg-popover text-foreground shadow-e3',
                'px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]',
                'motion-safe:transition-transform motion-safe:duration-200 motion-safe:ease-out data-[ending-style]:translate-y-full data-[starting-style]:translate-y-full',
                centeredFromMd &&
                    'md:inset-0 md:m-auto md:h-fit md:max-h-[calc(100dvh-2rem)] md:max-w-sm md:rounded-4xl md:pt-5 md:motion-safe:transition-[transform,opacity] md:data-[ending-style]:translate-y-0 md:data-[ending-style]:scale-95 md:data-[ending-style]:opacity-0 md:data-[starting-style]:translate-y-0 md:data-[starting-style]:scale-95 md:data-[starting-style]:opacity-0',
                className,
            )}
            style={
                dragY === null
                    ? undefined
                    : {
                          transform: `translateY(${dragY}px)`,
                          transition: 'none',
                      }
            }
        >
            <div
                data-testid="sheet-grip"
                className={cn(
                    '-mx-5 flex touch-none justify-center px-5 pb-3 pt-4',
                    centeredFromMd && 'md:hidden',
                )}
                onPointerDown={(event) => {
                    event.currentTarget.setPointerCapture?.(event.pointerId);
                    startYRef.current = event.clientY;
                    setDragY(0);
                }}
                onPointerMove={(event) => {
                    if (startYRef.current !== null) {
                        setDragY(
                            Math.max(0, event.clientY - startYRef.current),
                        );
                    }
                }}
                onPointerUp={() => endDrag(true)}
                onPointerCancel={() => endDrag(false)}
            >
                <span
                    aria-hidden
                    className="h-1 w-10 rounded-full bg-border-strong"
                />
            </div>
            {lead}
            <OverlayTitle className="font-serif text-headline-sm text-foreground">
                {title}
            </OverlayTitle>
            {children}
        </Overlay>
    );
}

/** The explicit close action a sheet's own footer renders. */
export function SheetClose({
    className,
    children,
}: Readonly<{ className?: string; children: ReactNode }>) {
    return <OverlayClose className={className}>{children}</OverlayClose>;
}
