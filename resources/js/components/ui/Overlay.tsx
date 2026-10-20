import { Dialog } from '@base-ui/react/dialog';
import {
    type ComponentProps,
    createContext,
    type ReactNode,
    type RefObject,
    use,
    useSyncExternalStore,
} from 'react';

import { useOverlayHistory } from '@/hooks/useOverlayHistory';

const subscribeNever = () => () => undefined;

/** Where overlays portal to; document.body unless a subtree provides an element. */
export const OverlayContainerContext =
    createContext<RefObject<HTMLElement | null> | null>(null);

/**
 * Every app overlay rests on this: Base UI's modal Dialog owns the focus trap
 * and return, the scroll lock, stacking and the escape/outside-press
 * dismissals, and {@link useOverlayHistory} makes Back close the topmost one.
 * Callers keep their own look through the backdrop and popup props.
 */
export default function Overlay({
    open,
    onOpenChange,
    backdropProps,
    children,
    ...popupProps
}: Readonly<
    {
        open: boolean;
        onOpenChange: (open: boolean) => void;
        backdropProps?: ComponentProps<typeof Dialog.Backdrop> & {
            'data-testid'?: string;
        };
        children: ReactNode;
    } & Omit<ComponentProps<typeof Dialog.Popup>, 'children'>
>) {
    useOverlayHistory(open, () => onOpenChange(false));
    const containerRef = use(OverlayContainerContext);
    // The provider's ref attaches after an overlay that opens with it has mounted, so read it again once committed.
    const container = useSyncExternalStore(subscribeNever, () =>
        containerRef ? containerRef.current : undefined,
    );

    return (
        <Dialog.Root open={open} onOpenChange={(next) => onOpenChange(next)}>
            <Dialog.Portal container={container}>
                <Dialog.Backdrop {...backdropProps} />
                <Dialog.Popup aria-modal {...popupProps}>
                    {children}
                </Dialog.Popup>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

export const OverlayTitle = Dialog.Title;
export const OverlayClose = Dialog.Close;
