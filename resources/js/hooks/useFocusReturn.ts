import { useEffect } from 'react';

/**
 * Stores the currently-focused element when a popover opens and restores
 * focus to it on close or unmount. No tab-trapping — for non-modal
 * disclosure popovers (dropdown menus, filter panels, tooltips), not
 * dialogs. See {@link useFocusTrap} for the modal variant that also traps
 * Tab inside the panel.
 *
 * SSR-safe: no-ops when there is no document.
 */
export function useFocusReturn(isOpen: boolean): void {
    useEffect(() => {
        if (!isOpen || typeof document === 'undefined') {
            return;
        }
        const previouslyFocused = document.activeElement as HTMLElement | null;

        return () => {
            if (
                previouslyFocused !== null &&
                typeof previouslyFocused.focus === 'function'
            ) {
                previouslyFocused.focus();
            }
        };
    }, [isOpen]);
}
