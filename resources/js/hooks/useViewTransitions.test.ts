import { router } from '@inertiajs/react';
import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import useViewTransitions from './useViewTransitions';

type Visit = { showProgress: boolean; viewTransition?: boolean };

type BeforeHandler = (event: { detail: { visit: Visit } }) => void;

function fireBefore(showProgress: boolean): Visit {
    const handler = vi
        .mocked(router.on)
        .mock.calls.at(-1)?.[1] as BeforeHandler;
    const visit: Visit = { showProgress };
    handler({ detail: { visit } });

    return visit;
}

function setReducedMotion(reduce: boolean): void {
    window.matchMedia = vi.fn().mockReturnValue({ matches: reduce });
}

describe('useViewTransitions', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
        setReducedMotion(false);
    });

    it('arms a transition on a real navigation', () => {
        renderHook(() => useViewTransitions());

        expect(vi.mocked(router.on).mock.calls.at(-1)?.[0]).toBe('before');
        expect(fireBefore(true).viewTransition).toBe(true);
    });

    it('leaves a background reload alone, since a poll tick is not a navigation', () => {
        renderHook(() => useViewTransitions());

        expect(fireBefore(false).viewTransition).toBeUndefined();
    });

    it('declines when the OS asks for reduced motion', () => {
        renderHook(() => useViewTransitions());
        setReducedMotion(true);

        expect(fireBefore(true).viewTransition).toBeUndefined();
    });

    it('reads the motion preference per visit, not once on mount', () => {
        renderHook(() => useViewTransitions());

        expect(fireBefore(true).viewTransition).toBe(true);

        setReducedMotion(true);
        expect(fireBefore(true).viewTransition).toBeUndefined();
    });

    it('stops listening when the shell unmounts', () => {
        const off = vi.fn();
        vi.mocked(router.on).mockReturnValueOnce(off);

        renderHook(() => useViewTransitions()).unmount();

        expect(off).toHaveBeenCalled();
    });
});
