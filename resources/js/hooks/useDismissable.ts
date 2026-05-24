import { type RefObject, useEffect } from 'react';

export function useDismissable(
    isOpen: boolean,
    containerRef: RefObject<HTMLElement | null>,
    onClose: () => void,
) {
    useEffect(() => {
        if (!isOpen) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        const onPointer = (e: PointerEvent) => {
            if (!containerRef.current?.contains(e.target as Node)) onClose();
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onPointer);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer);
        };
    }, [isOpen, onClose, containerRef]);
}
