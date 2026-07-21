import { useEffect } from 'react';

/**
 * Freezes page scroll while an overlay is open.
 *
 * Without it the document keeps scrolling under an open sheet or modal, which
 * is one of the loudest remaining "this is a web page" tells: native sheets
 * pin what is behind them. It also stops the scroll position drifting while a
 * dialog has focus, so dismissing returns you where you were.
 *
 * Refcounted at module scope rather than per-hook because overlays overlap —
 * a card reveal can open the share modal on top of it. If each instance
 * restored on its own cleanup, unmounting the inner one would unlock the page
 * while the outer overlay is still up. Only the last release restores, and it
 * restores the value that was there before the first lock rather than assuming
 * an empty string.
 */
let lockCount = 0;
let previousOverflow = '';

export function useBodyScrollLock(active: boolean): void {
    useEffect(() => {
        if (!active) {
            return;
        }

        if (lockCount === 0) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
        lockCount++;

        return () => {
            lockCount--;
            if (lockCount === 0) {
                document.body.style.overflow = previousOverflow;
            }
        };
    }, [active]);
}

export default useBodyScrollLock;
