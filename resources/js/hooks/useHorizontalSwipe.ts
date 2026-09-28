import type { TouchEventHandler } from 'react';

import { useCallback, useRef } from 'react';

export type HorizontalSwipeDirection = 'left' | 'right';

const EDGE_GUTTER = 32;

export function useHorizontalSwipe(
    onSwipe: (direction: HorizontalSwipeDirection) => void,
) {
    const startRef = useRef<{
        identifier: number;
        x: number;
        y: number;
    } | null>(null);

    const onTouchStart: TouchEventHandler<HTMLDivElement> = useCallback(
        (event) => {
            if (event.touches.length > 1) {
                startRef.current = null;
                return;
            }
            if (startRef.current !== null) return;

            const touch = event.touches[0];
            if (
                !touch ||
                touch.clientX < EDGE_GUTTER ||
                touch.clientX > window.innerWidth - EDGE_GUTTER
            ) {
                return;
            }

            startRef.current = {
                identifier: touch.identifier,
                x: touch.clientX,
                y: touch.clientY,
            };
        },
        [],
    );

    const onTouchEnd: TouchEventHandler<HTMLDivElement> = useCallback(
        (event) => {
            const start = startRef.current;
            if (start === null) return;

            const touch = Array.from(event.changedTouches).find(
                (changedTouch) => changedTouch.identifier === start.identifier,
            );
            if (!touch) return;

            startRef.current = null;
            const deltaX = touch.clientX - start.x;
            const deltaY = touch.clientY - start.y;
            if (Math.abs(deltaX) > 40 && Math.abs(deltaX) > Math.abs(deltaY)) {
                onSwipe(deltaX < 0 ? 'left' : 'right');
            }
        },
        [onSwipe],
    );

    const onTouchCancel: TouchEventHandler<HTMLDivElement> = useCallback(() => {
        startRef.current = null;
    }, []);

    return { onTouchStart, onTouchEnd, onTouchCancel };
}
