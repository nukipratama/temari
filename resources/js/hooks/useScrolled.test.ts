import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useScrolled } from './useScrolled';

function scrollTo(y: number) {
    act(() => {
        window.scrollY = y;
        window.dispatchEvent(new Event('scroll'));
    });
}

describe('useScrolled', () => {
    afterEach(() => {
        window.scrollY = 0;
    });

    it('starts false at the top of the document', () => {
        const { result } = renderHook(() => useScrolled());
        expect(result.current).toBe(false);
    });

    it('reads the current offset on mount rather than waiting for a scroll', () => {
        window.scrollY = 200;
        const { result } = renderHook(() => useScrolled());
        expect(result.current).toBe(true);
    });

    it('flips to true once past the threshold and back at the top', () => {
        const { result } = renderHook(() => useScrolled());

        scrollTo(50);
        expect(result.current).toBe(true);

        scrollTo(0);
        expect(result.current).toBe(false);
    });

    it('honours a custom threshold', () => {
        const { result } = renderHook(() => useScrolled(100));

        scrollTo(80);
        expect(result.current).toBe(false);

        scrollTo(120);
        expect(result.current).toBe(true);
    });

    it('stops responding after unmount', () => {
        const { result, unmount } = renderHook(() => useScrolled());
        unmount();

        scrollTo(500);
        expect(result.current).toBe(false);
    });
});
