import { useEffect } from 'react';
import { isStandalone } from '@/lib/webPush';

/** How far from the left edge a drag must start to count as a back gesture. */
const EDGE_PX = 24;
/** Fraction of the viewport that commits the navigation on release. */
const COMMIT_RATIO = 0.35;
/** A flick this fast (px/ms) commits regardless of distance. */
const COMMIT_VELOCITY = 0.5;
/** Travel before we decide the gesture is horizontal rather than a scroll. */
const DIRECTION_LOCK_PX = 10;

/**
 * Walks up from the touch target looking for something that scrolls sideways
 * (a stat strip, a chart, a map). Those own their horizontal drags, so a
 * gesture starting inside one is not a back swipe even at the screen edge.
 */
function insideHorizontalScroller(target: EventTarget | null): boolean {
    let node = target instanceof Element ? target : null;

    while (node) {
        if (node.scrollWidth > node.clientWidth + 1) {
            const { overflowX } = window.getComputedStyle(node);
            if (overflowX === 'auto' || overflowX === 'scroll') {
                return true;
            }
        }
        node = node.parentElement;
    }

    return false;
}

/**
 * Left-edge swipe to go back, for the installed app.
 *
 * A standalone PWA has no browser chrome, so without this there is no back
 * affordance at all on a detail page beyond the tab bar — the single biggest
 * "this is not a real app" gap on iOS. Only armed when actually running
 * standalone on a touch screen; in a browser tab the real back button and
 * Safari's own edge swipe already exist and a second handler would fight them.
 */
export function useSwipeBack(): void {
    useEffect(() => {
        if (!isStandalone() || !window.matchMedia('(pointer: coarse)').matches) {
            return;
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        let startX = 0;
        let startY = 0;
        let startedAt = 0;
        let tracking = false;
        let locked = false;
        let page: HTMLElement | null = null;
        let settleTimer = 0;

        const release = (transition: string, transform: string) => {
            if (!page) {
                return;
            }
            page.style.transition = transition;
            page.style.transform = transform;

            const settling = page;
            window.clearTimeout(settleTimer);
            settleTimer = window.setTimeout(() => {
                settling.style.transition = '';
                settling.style.transform = '';
            }, 240);
        };

        const reset = () => {
            tracking = false;
            locked = false;
            page = null;
        };

        const onTouchStart = (event: TouchEvent) => {
            const touch = event.touches[0];
            if (
                event.touches.length !== 1 ||
                touch.clientX > EDGE_PX ||
                window.history.length <= 1 ||
                insideHorizontalScroller(event.target)
            ) {
                return;
            }

            startX = touch.clientX;
            startY = touch.clientY;
            startedAt = event.timeStamp;
            tracking = true;
            locked = false;
            page = document.getElementById('main-content');
        };

        const onTouchMove = (event: TouchEvent) => {
            if (!tracking) {
                return;
            }

            const touch = event.touches[0];
            const dx = touch.clientX - startX;
            const dy = touch.clientY - startY;

            if (!locked) {
                if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > DIRECTION_LOCK_PX) {
                    // Vertical intent — hand the gesture back to the scroller.
                    reset();
                    return;
                }
                if (Math.abs(dx) < DIRECTION_LOCK_PX) {
                    return;
                }
                locked = true;
            }

            if (dx <= 0) {
                return;
            }

            if (page && !reducedMotion) {
                page.style.transition = 'none';
                page.style.transform = `translateX(${dx}px)`;
                page.style.opacity = String(Math.max(0.4, 1 - dx / window.innerWidth));
            }
        };

        const onTouchEnd = (event: TouchEvent) => {
            if (!tracking) {
                return;
            }

            const touch = event.changedTouches[0];
            const dx = touch.clientX - startX;
            const elapsed = Math.max(1, event.timeStamp - startedAt);
            const commit = locked && (dx > window.innerWidth * COMMIT_RATIO || dx / elapsed > COMMIT_VELOCITY);

            if (page) {
                page.style.opacity = '';
            }

            if (commit) {
                // Let the page finish sliding out before the history pop swaps
                // it, so the navigation reads as one continuous movement.
                release('transform 160ms ease-out', reducedMotion ? '' : `translateX(${window.innerWidth}px)`);
                window.history.back();
            } else if (locked) {
                release('transform 200ms cubic-bezier(0.34, 1.1, 0.64, 1)', '');
            }

            reset();
        };

        document.addEventListener('touchstart', onTouchStart, { passive: true });
        document.addEventListener('touchmove', onTouchMove, { passive: true });
        document.addEventListener('touchend', onTouchEnd, { passive: true });
        document.addEventListener('touchcancel', reset, { passive: true });

        return () => {
            window.clearTimeout(settleTimer);
            document.removeEventListener('touchstart', onTouchStart);
            document.removeEventListener('touchmove', onTouchMove);
            document.removeEventListener('touchend', onTouchEnd);
            document.removeEventListener('touchcancel', reset);
        };
    }, []);
}
