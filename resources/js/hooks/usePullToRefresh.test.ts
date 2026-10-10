import { fireEvent, renderHook, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    PULL_MAX,
    PULL_THRESHOLD,
    resistedPull,
    usePullToRefresh,
} from './usePullToRefresh';

function mount({
    enabled = true,
    onRefresh,
}: Readonly<{ enabled?: boolean; onRefresh: () => void }>) {
    document.body.innerHTML = `
        <div data-testid="area">
            <div data-testid="inner">content</div>
            <div data-testid="scroller" style="overflow-x: auto">
                <span data-testid="in-scroller">row</span>
            </div>
            <div data-no-pull-refresh>
                <span data-testid="in-opt-out">chart</span>
            </div>
        </div>`;
    const ref = { current: screen.getByTestId('area') };

    return renderHook(() => usePullToRefresh(ref, enabled, onRefresh)).result;
}

function touch(clientY: number) {
    return { touches: [{ identifier: 1, clientX: 10, clientY }] };
}

function setDocumentScroll(top: number) {
    Object.defineProperty(window, 'scrollY', {
        configurable: true,
        value: top,
    });
}

function pullTo(target: Element, from: number, to: number) {
    fireEvent.touchStart(target, touch(from));
    const moved = fireEvent.touchMove(target, touch(to));
    fireEvent.touchEnd(target, { changedTouches: [] });
    return moved;
}

afterEach(() => {
    setDocumentScroll(0);
    document.body.innerHTML = '';
});

describe('resistedPull', () => {
    it('resists the finger distance and caps past the threshold', () => {
        expect(resistedPull(0)).toBe(0);
        expect(resistedPull(50)).toBeLessThan(50);
        expect(resistedPull(100)).toBeCloseTo(PULL_THRESHOLD);
        expect(resistedPull(120)).toBeGreaterThan(PULL_THRESHOLD);
        expect(resistedPull(1000)).toBe(PULL_MAX);
    });
});

describe('usePullToRefresh', () => {
    it('follows a downward pull from the top and cancels the native pan', () => {
        const result = mount({ onRefresh: vi.fn() });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, touch(100));
        const notPrevented = fireEvent.touchMove(inner, touch(140));

        expect(notPrevented).toBe(false);
        expect(result.current.pulling).toBe(true);
        expect(result.current.pull).toBeCloseTo(resistedPull(40));
    });

    it('refreshes when released past the threshold and resets', () => {
        const onRefresh = vi.fn();
        const result = mount({ onRefresh });

        pullTo(screen.getByTestId('inner'), 100, 210);

        expect(onRefresh).toHaveBeenCalledTimes(1);
        expect(result.current.pulling).toBe(false);
        expect(result.current.pull).toBe(0);
    });

    it('does not refresh when released short of the threshold', () => {
        const onRefresh = vi.fn();
        const result = mount({ onRefresh });

        pullTo(screen.getByTestId('inner'), 100, 150);

        expect(onRefresh).not.toHaveBeenCalled();
        expect(result.current.pull).toBe(0);
    });

    it('ignores a pull that starts below the top of the document', () => {
        setDocumentScroll(30);
        const onRefresh = vi.fn();
        mount({ onRefresh });

        const notPrevented = pullTo(screen.getByTestId('inner'), 100, 260);

        expect(notPrevented).toBe(true);
        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('abandons the pull when the page scrolls mid-gesture', () => {
        const onRefresh = vi.fn();
        const result = mount({ onRefresh });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, touch(100));
        fireEvent.touchMove(inner, touch(140));
        setDocumentScroll(10);
        fireEvent.touchMove(inner, touch(260));
        fireEvent.touchEnd(inner, { changedTouches: [] });

        expect(onRefresh).not.toHaveBeenCalled();
        expect(result.current.pull).toBe(0);
    });

    it('ignores an upward drag and a later reversal', () => {
        const onRefresh = vi.fn();
        mount({ onRefresh });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, touch(200));
        fireEvent.touchMove(inner, touch(150));
        const notPrevented = fireEvent.touchMove(inner, touch(400));
        fireEvent.touchEnd(inner, { changedTouches: [] });

        expect(notPrevented).toBe(true);
        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('ignores a multi-finger gesture', () => {
        const onRefresh = vi.fn();
        mount({ onRefresh });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, {
            touches: [
                { identifier: 1, clientX: 10, clientY: 100 },
                { identifier: 2, clientX: 50, clientY: 100 },
            ],
        });
        const notPrevented = fireEvent.touchMove(inner, touch(260));

        expect(notPrevented).toBe(true);
    });

    it('drops an engaged pull when a second finger lands', () => {
        const onRefresh = vi.fn();
        const result = mount({ onRefresh });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, touch(100));
        fireEvent.touchMove(inner, touch(260));
        fireEvent.touchStart(inner, {
            touches: [
                { identifier: 1, clientX: 10, clientY: 260 },
                { identifier: 2, clientX: 50, clientY: 100 },
            ],
        });

        expect(result.current.pulling).toBe(false);
        expect(result.current.pull).toBe(0);

        fireEvent.touchEnd(inner, { changedTouches: [] });

        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('ignores a pull that starts inside a horizontal scroller', () => {
        mount({ onRefresh: vi.fn() });
        const row = screen.getByTestId('in-scroller');
        const host = screen.getByTestId('scroller');
        Object.defineProperty(host, 'scrollWidth', { value: 600 });
        Object.defineProperty(host, 'clientWidth', { value: 300 });

        const notPrevented = pullTo(row, 100, 260);

        expect(notPrevented).toBe(true);
    });

    it('still pulls inside an overflow-x element that does not actually scroll', () => {
        mount({ onRefresh: vi.fn() });

        const notPrevented = pullTo(
            screen.getByTestId('in-scroller'),
            100,
            260,
        );

        expect(notPrevented).toBe(false);
    });

    it('ignores a pull that starts inside an opted-out region', () => {
        const onRefresh = vi.fn();
        mount({ onRefresh });

        const notPrevented = pullTo(screen.getByTestId('in-opt-out'), 100, 260);

        expect(notPrevented).toBe(true);
        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('does nothing while disabled', () => {
        const onRefresh = vi.fn();
        mount({ enabled: false, onRefresh });

        const notPrevented = pullTo(screen.getByTestId('inner'), 100, 260);

        expect(notPrevented).toBe(true);
        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('resets on touchcancel without refreshing', () => {
        const onRefresh = vi.fn();
        const result = mount({ onRefresh });
        const inner = screen.getByTestId('inner');

        fireEvent.touchStart(inner, touch(100));
        fireEvent.touchMove(inner, touch(260));
        fireEvent.touchCancel(inner);

        expect(onRefresh).not.toHaveBeenCalled();
        expect(result.current.pull).toBe(0);
    });
});
