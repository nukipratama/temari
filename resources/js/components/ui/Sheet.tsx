import { Dialog } from '@base-ui/react/dialog';
import { type ReactNode, useRef, useState } from 'react';

import { cn } from '@/lib/cn';

/** How far down the sheet must travel before letting go dismisses it. */
export const SWIPE_DISMISS_PX = 80;

/**
 * A bottom sheet on Base UI's Dialog, which owns the focus trap, the body
 * scroll lock and the escape/outside-press dismissals. Everything on top of it
 * is CSS: the slide-up is a transition keyed off Base UI's own
 * `data-starting-style` / `data-ending-style`, not framer-motion, so the sheet
 * stays usable from the bare layout whose entry chunk carries no motion
 * library.
 */
export default function Sheet({
    open,
    onOpenChange,
    title,
    children,
}: Readonly<{
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
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
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Backdrop
                    data-testid="sheet-scrim"
                    className="fixed inset-0 z-40 bg-sky/60 transition-opacity duration-200 data-[ending-style]:opacity-0 data-[starting-style]:opacity-0"
                />
                <Dialog.Popup
                    className={cn(
                        'fixed inset-x-0 bottom-0 z-50 mx-auto flex max-h-[85svh] w-full max-w-lg flex-col overflow-y-auto rounded-t-4xl bg-popover text-foreground shadow-e3',
                        'px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]',
                        'transition-transform duration-200 ease-out data-[ending-style]:translate-y-full data-[starting-style]:translate-y-full',
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
                        className="-mx-5 flex touch-none justify-center px-5 pb-3 pt-4"
                        onPointerDown={(event) => {
                            event.currentTarget.setPointerCapture?.(
                                event.pointerId,
                            );
                            startYRef.current = event.clientY;
                            setDragY(0);
                        }}
                        onPointerMove={(event) => {
                            if (startYRef.current !== null) {
                                setDragY(
                                    Math.max(
                                        0,
                                        event.clientY - startYRef.current,
                                    ),
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
                    <Dialog.Title className="font-serif text-headline-sm text-foreground">
                        {title}
                    </Dialog.Title>
                    {children}
                </Dialog.Popup>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

/** The explicit close action a sheet's own footer renders. */
export function SheetClose({
    className,
    children,
}: Readonly<{ className?: string; children: ReactNode }>) {
    return <Dialog.Close className={className}>{children}</Dialog.Close>;
}
