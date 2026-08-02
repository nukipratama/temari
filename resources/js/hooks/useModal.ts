import { type RefObject } from 'react';

import { useBodyScrollLock } from './useBodyScrollLock';
import { useDismissable } from './useDismissable';
import { useFocusTrap } from './useFocusTrap';

export function useModal(
    isOpen: boolean,
    panelRef: RefObject<HTMLElement | null>,
    onClose: () => void,
): void {
    useDismissable(isOpen, panelRef, onClose);
    useFocusTrap(isOpen, panelRef);
    useBodyScrollLock(isOpen);
}
