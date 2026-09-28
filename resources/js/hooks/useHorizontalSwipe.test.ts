import { fireEvent, render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import {
    useHorizontalSwipe,
    type HorizontalSwipeDirection,
} from './useHorizontalSwipe';

function SwipeTarget({
    onSwipe,
}: Readonly<{ onSwipe: (direction: HorizontalSwipeDirection) => void }>) {
    return createElement('div', {
        'data-testid': 'touch-area',
        ...useHorizontalSwipe(onSwipe),
    });
}

function renderTarget() {
    const onSwipe = vi.fn();
    render(createElement(SwipeTarget, { onSwipe }));

    return { area: screen.getByTestId('touch-area'), onSwipe };
}

describe('useHorizontalSwipe', () => {
    it('tracks the starting finger and swipes only past the horizontal threshold', () => {
        const { area, onSwipe } = renderTarget();

        fireEvent.touchStart(area, {
            touches: [{ identifier: 1, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 2, clientX: 100, clientY: 100 }],
        });
        expect(onSwipe).not.toHaveBeenCalled();

        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 1, clientX: 160, clientY: 100 }],
        });
        expect(onSwipe).not.toHaveBeenCalled();

        fireEvent.touchStart(area, {
            touches: [{ identifier: 3, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 3, clientX: 150, clientY: 110 }],
        });
        expect(onSwipe).toHaveBeenCalledWith('left');
    });

    it('ignores vertical and diagonal-dominant movement without cancelling touch events', () => {
        const { area, onSwipe } = renderTarget();

        expect(
            fireEvent.touchStart(area, {
                touches: [{ identifier: 1, clientX: 200, clientY: 100 }],
            }),
        ).toBe(true);
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 1, clientX: 155, clientY: 300 }],
        });

        fireEvent.touchStart(area, {
            touches: [{ identifier: 2, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 2, clientX: 99, clientY: 201 }],
        });

        expect(onSwipe).not.toHaveBeenCalled();
    });

    it('leaves the browser edge-navigation gutters alone', () => {
        const { area, onSwipe } = renderTarget();

        fireEvent.touchStart(area, {
            touches: [{ identifier: 1, clientX: 16, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 1, clientX: 116, clientY: 100 }],
        });

        const width = window.innerWidth;
        fireEvent.touchStart(area, {
            touches: [{ identifier: 2, clientX: width - 16, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [
                { identifier: 2, clientX: width - 116, clientY: 100 },
            ],
        });

        expect(onSwipe).not.toHaveBeenCalled();
    });

    it('abandons interleaved multi-touch and resets after cancellation', () => {
        const { area, onSwipe } = renderTarget();
        const first = { identifier: 1, clientX: 200, clientY: 100 };
        const second = { identifier: 2, clientX: 100, clientY: 100 };

        fireEvent.touchStart(area, { touches: [first] });
        fireEvent.touchStart(area, { touches: [first, second] });
        fireEvent.touchEnd(area, {
            touches: [second],
            changedTouches: [{ identifier: 1, clientX: 100, clientY: 105 }],
        });
        fireEvent.touchEnd(area, { touches: [], changedTouches: [second] });
        expect(onSwipe).not.toHaveBeenCalled();

        fireEvent.touchStart(area, {
            touches: [{ identifier: 3, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchCancel(area);
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 3, clientX: 100, clientY: 100 }],
        });
        expect(onSwipe).not.toHaveBeenCalled();

        fireEvent.touchStart(area, {
            touches: [{ identifier: 4, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchEnd(area, {
            changedTouches: [{ identifier: 4, clientX: 100, clientY: 105 }],
        });
        expect(onSwipe).toHaveBeenCalledWith('left');
    });
});
