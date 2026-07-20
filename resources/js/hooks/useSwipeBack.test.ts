import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useSwipeBack } from './useSwipeBack';
import { isStandalone } from '@/lib/webPush';

vi.mock('@/lib/webPush', () => ({ isStandalone: vi.fn() }));

/**
 * jsdom ships no Touch/TouchEvent constructors, so the listeners are fed
 * minimal shaped events instead.
 */
function touch(type: string, x: number, y: number, target: Element = document.body, timeStamp = 0) {
    const event = new Event(type, { bubbles: true });
    const list = [{ clientX: x, clientY: y }];
    Object.defineProperties(event, {
        touches: { value: type === 'touchend' ? [] : list },
        changedTouches: { value: list },
        target: { value: target },
        timeStamp: { value: timeStamp },
    });
    document.dispatchEvent(event);
}

function swipe(from: number, to: number, { y = 200, elapsed = 100 } = {}) {
    touch('touchstart', from, y, document.body, 0);
    touch('touchmove', from + 12, y, document.body, elapsed / 2);
    touch('touchmove', to, y, document.body, elapsed);
    touch('touchend', to, y, document.body, elapsed);
}

describe('useSwipeBack', () => {
    let back: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.mocked(isStandalone).mockReturnValue(true);
        window.matchMedia = vi.fn().mockImplementation((query: string) => ({
            matches: query === '(pointer: coarse)',
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        }));
        back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        vi.spyOn(window.history, 'length', 'get').mockReturnValue(4);
        Object.defineProperty(window, 'innerWidth', { value: 390, configurable: true });

        const main = document.createElement('div');
        main.id = 'main-content';
        document.body.append(main);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.getElementById('main-content')?.remove();
    });

    it('goes back on a slow drag past the commit distance', () => {
        renderHook(() => useSwipeBack());
        // 390 * 0.35 = 136.5px commits; 300px over 2s is too slow to be a flick.
        swipe(8, 300, { elapsed: 2000 });
        expect(back).toHaveBeenCalledOnce();
    });

    it('goes back on a short fast flick', () => {
        renderHook(() => useSwipeBack());
        swipe(8, 90, { elapsed: 60 });
        expect(back).toHaveBeenCalledOnce();
    });

    it('springs back when the drag is short and slow', () => {
        renderHook(() => useSwipeBack());
        swipe(8, 60, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });

    it('ignores drags that do not start at the left edge', () => {
        renderHook(() => useSwipeBack());
        swipe(120, 380, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });

    it('ignores a vertical scroll that begins near the edge', () => {
        renderHook(() => useSwipeBack());
        touch('touchstart', 8, 100, document.body, 0);
        touch('touchmove', 10, 300, document.body, 50);
        touch('touchend', 10, 300, document.body, 60);
        expect(back).not.toHaveBeenCalled();
    });

    it('leaves the gesture to a horizontally scrollable element', () => {
        const strip = document.createElement('div');
        Object.defineProperties(strip, {
            scrollWidth: { value: 900 },
            clientWidth: { value: 300 },
        });
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({ overflowX: 'auto' } as CSSStyleDeclaration);
        document.body.append(strip);

        renderHook(() => useSwipeBack());
        touch('touchstart', 8, 200, strip, 0);
        touch('touchmove', 300, 200, strip, 100);
        touch('touchend', 300, 200, strip, 100);

        expect(back).not.toHaveBeenCalled();
        strip.remove();
    });

    it('stays disarmed in a browser tab rather than fighting Safari\'s own edge swipe', () => {
        vi.mocked(isStandalone).mockReturnValue(false);
        renderHook(() => useSwipeBack());
        swipe(8, 300, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });

    it('stays disarmed on a pointer-precise device', () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false });
        renderHook(() => useSwipeBack());
        swipe(8, 300, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });

    it('does nothing when there is no history to pop', () => {
        vi.spyOn(window.history, 'length', 'get').mockReturnValue(1);
        renderHook(() => useSwipeBack());
        swipe(8, 300, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });

    it('detaches its listeners on unmount', () => {
        const { unmount } = renderHook(() => useSwipeBack());
        unmount();
        swipe(8, 300, { elapsed: 2000 });
        expect(back).not.toHaveBeenCalled();
    });
});
