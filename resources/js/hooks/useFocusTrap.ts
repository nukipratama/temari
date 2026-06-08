import { type RefObject, useEffect } from 'react';

const TABBABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function tabbablesIn(panel: HTMLElement): HTMLElement[] {
    return Array.from(panel.querySelectorAll<HTMLElement>(TABBABLE_SELECTOR)).filter(
        (el) => !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true',
    );
}

/**
 * Traps keyboard focus inside an open dialog panel. On open it stores the
 * previously-focused element, moves focus into the panel (its first tabbable,
 * or the panel itself), and keeps Tab / Shift+Tab cycling within the panel.
 * On close or unmount it restores focus to the stored element.
 *
 * SSR-/null-safe: no-ops when there is no document or the panel ref is empty.
 */
export function useFocusTrap(isOpen: boolean, panelRef: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        if (!isOpen || typeof document === 'undefined') {
            return;
        }
        const panel = panelRef.current;
        if (panel === null) {
            return;
        }

        const previouslyFocused = document.activeElement as HTMLElement | null;

        const first = tabbablesIn(panel)[0];
        if (first === undefined) {
            if (!panel.hasAttribute('tabindex')) {
                panel.setAttribute('tabindex', '-1');
            }
            panel.focus();
        } else {
            first.focus();
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Tab') {
                return;
            }
            const tabbables = tabbablesIn(panel);
            if (tabbables.length === 0) {
                e.preventDefault();
                panel.focus();
                return;
            }
            const firstEl = tabbables[0];
            const lastEl = tabbables.at(-1) ?? firstEl;
            const active = document.activeElement;
            const outsidePanel = active === null || !panel.contains(active);

            if (e.shiftKey) {
                if (active === firstEl || active === panel || outsidePanel) {
                    e.preventDefault();
                    lastEl.focus();
                }
            } else if (active === lastEl || outsidePanel) {
                e.preventDefault();
                firstEl.focus();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            if (previouslyFocused !== null && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
        };
    }, [isOpen, panelRef]);
}
