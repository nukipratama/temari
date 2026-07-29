import { type RefObject } from 'react';
import { useDismissable } from './useDismissable';
import { useFocusReturn } from './useFocusReturn';

export function usePopover(
    isOpen: boolean,
    containerRef: RefObject<HTMLElement | null>,
    onClose: () => void,
): void {
    useDismissable(isOpen, containerRef, onClose);
    useFocusReturn(isOpen);
}
