import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useRef } from 'react';
import { useGaze } from './useGaze';

interface MQStub {
    matches: boolean;
    addEventListener: (k: string, cb: () => void) => void;
    removeEventListener: (k: string, cb: () => void) => void;
}

function mockMatchMedia(map: Record<string, boolean>) {
    const fn = vi.fn((q: string): MQStub => ({
        matches: map[q] ?? false,
        addEventListener: () => {},
        removeEventListener: () => {},
    }));
    Object.defineProperty(globalThis, 'matchMedia', { configurable: true, writable: true, value: fn });
    return fn;
}

beforeEach(() => {
    // ensure rAF runs synchronously
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
        cb(performance.now());
        return 1;
    });
    vi.stubGlobal('cancelAnimationFrame', () => {});
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function makeRef() {
    const ref = { current: document.createElement('div') };
    Object.defineProperty(ref.current, 'getBoundingClientRect', {
        value: () => ({ left: 100, top: 100, width: 100, height: 100, right: 200, bottom: 200, x: 100, y: 100, toJSON: () => ({}) }),
    });
    return ref;
}

describe('useGaze', () => {
    it('returns zeroes when reduced-motion is set', () => {
        mockMatchMedia({ '(prefers-reduced-motion: reduce)': true, '(pointer: fine)': true });
        const ref = makeRef();
        const { result } = renderHook(() => useGaze(ref));
        expect(result.current).toEqual({ x: 0, y: 0 });
    });

    it('returns zeroes when there is no fine pointer (touch-only)', () => {
        mockMatchMedia({ '(prefers-reduced-motion: reduce)': false, '(pointer: fine)': false });
        const ref = makeRef();
        const { result } = renderHook(() => useGaze(ref));
        expect(result.current).toEqual({ x: 0, y: 0 });
    });

    it('updates gaze on mousemove inside range', () => {
        mockMatchMedia({ '(prefers-reduced-motion: reduce)': false, '(pointer: fine)': true });
        const ref = makeRef();
        const { result } = renderHook(() => useGaze(ref, { range: 200, falloff: 100 }));
        act(() => {
            document.dispatchEvent(
                new MouseEvent('mousemove', { clientX: 200, clientY: 150 }),
            );
        });
        // mascot centre is (150, 150); cursor at (200, 150) → +x, 0 y, dist 50, within range → strength 1.
        expect(result.current.x).toBeCloseTo(1, 5);
        expect(result.current.y).toBeCloseTo(0, 5);
    });

    it('decays to zero past range + falloff', () => {
        mockMatchMedia({ '(prefers-reduced-motion: reduce)': false, '(pointer: fine)': true });
        const ref = makeRef();
        const { result } = renderHook(() => useGaze(ref, { range: 50, falloff: 50 }));
        act(() => {
            document.dispatchEvent(
                new MouseEvent('mousemove', { clientX: 999, clientY: 150 }),
            );
        });
        expect(result.current.x).toBeCloseTo(0, 1);
    });

    it('falls back to zero when ref has no element', () => {
        mockMatchMedia({ '(prefers-reduced-motion: reduce)': false, '(pointer: fine)': true });
        const { result } = renderHook(() => {
            const ref = useRef<HTMLElement | null>(null);
            return useGaze(ref);
        });
        expect(result.current).toEqual({ x: 0, y: 0 });
    });
});
