import { router } from '@inertiajs/react';
import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import useViewTransitions from './useViewTransitions';

type Visit = {
    showProgress: boolean;
    url: URL;
    viewTransition?: boolean | ((transition: ViewTransition) => void);
};

type BeforeHandler = (event: { detail: { visit: Visit } }) => void;

function fireBefore(
    showProgress: boolean,
    viewTransition?: Visit['viewTransition'],
    href = '/plan',
): Visit {
    const handler = vi
        .mocked(router.on)
        .mock.calls.at(-1)?.[1] as BeforeHandler;
    const visit: Visit = {
        showProgress,
        url: new URL(href, 'http://localhost'),
        viewTransition,
    };
    handler({ detail: { visit } });

    return visit;
}

function setMedia({ reduce = false, coarse = false } = {}): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: query.includes('coarse') ? coarse : reduce,
        })),
    );
}

function setReducedMotion(reduce: boolean): void {
    setMedia({ reduce });
}

describe('useViewTransitions', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
        setReducedMotion(false);
    });

    it('arms a transition on a real navigation', () => {
        renderHook(() => useViewTransitions('Home'));

        expect(vi.mocked(router.on).mock.calls.at(-1)?.[0]).toBe('before');
        expect(fireBefore(true).viewTransition).toBe(true);
    });

    it('leaves a background reload alone, since a poll tick is not a navigation', () => {
        renderHook(() => useViewTransitions('Home'));

        expect(fireBefore(false).viewTransition).toBeUndefined();
    });

    it('declines when the OS asks for reduced motion', () => {
        renderHook(() => useViewTransitions('Home'));
        setReducedMotion(true);

        expect(fireBefore(true).viewTransition).toBe(false);
    });

    it("keeps a link's own transition callback on a real navigation", () => {
        const morph = vi.fn<(transition: ViewTransition) => void>();
        renderHook(() => useViewTransitions('Home'));

        expect(fireBefore(true, morph).viewTransition).toBe(morph);
    });

    it("drops a link's own transition callback under reduced motion", () => {
        renderHook(() => useViewTransitions('Home'));
        setReducedMotion(true);

        expect(fireBefore(true, () => undefined).viewTransition).toBe(false);
    });

    it('reads the motion preference per visit, not once on mount', () => {
        renderHook(() => useViewTransitions('Home'));

        expect(fireBefore(true).viewTransition).toBe(true);

        setReducedMotion(true);
        expect(fireBefore(true).viewTransition).toBe(false);
    });

    it('stops listening when the shell unmounts', () => {
        const off = vi.fn();
        vi.mocked(router.on).mockReturnValueOnce(off);

        renderHook(() => useViewTransitions('Home')).unmount();

        expect(off).toHaveBeenCalled();
    });

    it('drops the morph from a run pushed on a phone, which slid on the tap instead', () => {
        setMedia({ coarse: true });
        renderHook(() => useViewTransitions('History'));

        expect(
            fireBefore(true, () => undefined, '/activities/12').viewTransition,
        ).toBe(false);
    });

    it('swaps a pop on a phone without a second transition', () => {
        setMedia({ coarse: true });
        renderHook(() => useViewTransitions('Runs/Show'));

        expect(fireBefore(true, undefined, '/history').viewTransition).toBe(
            false,
        );
    });

    it('keeps the cross-fade for a tab switch on a phone', () => {
        setMedia({ coarse: true });
        renderHook(() => useViewTransitions('Home'));

        expect(fireBefore(true, undefined, '/plan').viewTransition).toBe(true);
    });

    it('keeps the morph for a run opened under a fine pointer', () => {
        const morph = vi.fn<(transition: ViewTransition) => void>();
        renderHook(() => useViewTransitions('History'));

        expect(fireBefore(true, morph, '/activities/12').viewTransition).toBe(
            morph,
        );
    });

    it('judges the pop from the page on screen when the visit starts', () => {
        setMedia({ coarse: true });
        const { rerender } = renderHook(
            ({ component }) => useViewTransitions(component),
            { initialProps: { component: 'Plan' } },
        );
        window.history.replaceState(null, '', '/plan');

        expect(fireBefore(true, undefined, '/').viewTransition).toBe(true);

        rerender({ component: 'Inbox' });
        window.history.replaceState(null, '', '/inbox');

        expect(fireBefore(true, undefined, '/').viewTransition).toBe(false);
        window.history.replaceState(null, '', '/');
    });

    it('keeps the cross-fade for a reload of the pushed screen on show', () => {
        setMedia({ coarse: true });
        window.history.replaceState(null, '', '/inbox');
        renderHook(() => useViewTransitions('Inbox'));

        expect(fireBefore(true, undefined, '/inbox').viewTransition).toBe(true);
        window.history.replaceState(null, '', '/');
    });

    it('slides history traversals while mounted', () => {
        const add = vi.spyOn(window, 'addEventListener');
        const remove = vi.spyOn(window, 'removeEventListener');

        renderHook(() => useViewTransitions('Home')).unmount();

        expect(add).toHaveBeenCalledWith('popstate', expect.any(Function), {
            capture: true,
        });
        expect(remove).toHaveBeenCalledWith('popstate', expect.any(Function), {
            capture: true,
        });
        add.mockRestore();
        remove.mockRestore();
    });
});
