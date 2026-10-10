import { type RefObject, useEffect, useState } from 'react';

export const PULL_THRESHOLD = 70;
export const PULL_MAX = 100;
const PULL_OPT_OUT_ATTRIBUTE = 'data-no-pull-refresh';

const PULL_RATIO = 0.7;
const PAST_THRESHOLD_RATIO = 0.25;

export function resistedPull(distance: number): number {
    const pull = distance * PULL_RATIO;
    if (pull <= PULL_THRESHOLD) {
        return pull;
    }
    return Math.min(
        PULL_MAX,
        PULL_THRESHOLD + (pull - PULL_THRESHOLD) * PAST_THRESHOLD_RATIO,
    );
}

function startsInBlockedRegion(target: EventTarget | null): boolean {
    let element = target instanceof Element ? target : null;
    while (element !== null && element !== document.body) {
        if (element.hasAttribute(PULL_OPT_OUT_ATTRIBUTE)) {
            return true;
        }
        const { overflowX } = getComputedStyle(element);
        if (
            (overflowX === 'auto' || overflowX === 'scroll') &&
            element.scrollWidth > element.clientWidth
        ) {
            return true;
        }
        element = element.parentElement;
    }
    return false;
}

export function usePullToRefresh(
    ref: RefObject<HTMLElement | null>,
    enabled: boolean,
    onRefresh: () => void,
) {
    const [pull, setPull] = useState(0);
    const [pulling, setPulling] = useState(false);

    useEffect(() => {
        const element = ref.current;
        if (!enabled || element === null) {
            return;
        }

        let startY: number | null = null;
        let engaged = false;
        let distance = 0;

        const reset = () => {
            startY = null;
            engaged = false;
            distance = 0;
            setPulling(false);
            setPull(0);
        };

        const onTouchStart = (event: TouchEvent) => {
            reset();
            if (
                event.touches.length !== 1 ||
                window.scrollY > 0 ||
                startsInBlockedRegion(event.target)
            ) {
                return;
            }
            startY = event.touches[0]?.clientY ?? null;
        };

        const onTouchMove = (event: TouchEvent) => {
            if (startY === null) {
                return;
            }
            if (event.touches.length !== 1 || window.scrollY > 0) {
                reset();
                return;
            }
            const delta = (event.touches[0]?.clientY ?? startY) - startY;
            if (!engaged && delta <= 0) {
                startY = null;
                return;
            }
            engaged = true;
            distance = Math.max(0, delta);
            if (event.cancelable) {
                event.preventDefault();
            }
            setPulling(true);
            setPull(resistedPull(distance));
        };

        const onTouchEnd = () => {
            const release = engaged && resistedPull(distance) >= PULL_THRESHOLD;
            reset();
            if (release) {
                onRefresh();
            }
        };

        element.addEventListener('touchstart', onTouchStart, { passive: true });
        element.addEventListener('touchmove', onTouchMove, { passive: false });
        element.addEventListener('touchend', onTouchEnd);
        element.addEventListener('touchcancel', reset);
        return () => {
            element.removeEventListener('touchstart', onTouchStart);
            element.removeEventListener('touchmove', onTouchMove);
            element.removeEventListener('touchend', onTouchEnd);
            element.removeEventListener('touchcancel', reset);
        };
    }, [ref, enabled, onRefresh]);

    return { pull, pulling };
}
