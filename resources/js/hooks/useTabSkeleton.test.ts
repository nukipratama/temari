import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { armPendingTab, pendingTabSnapshot } from '@/lib/pendingTab';

import useTabSkeleton from './useTabSkeleton';

function handler<T extends 'start' | 'finish'>(name: T) {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as (event: GlobalEvent<T>) => void;
}

function tabVisit(href: string) {
    return {
        url: new URL(href, 'http://localhost'),
        method: 'get',
        async: false,
        prefetch: false,
    } as PendingVisit;
}

function tap(tab: 'plan' | 'trends', from: string) {
    const visit = tabVisit(`/${tab}`);
    act(() => {
        armPendingTab(tab, from);
        handler('start')({ detail: { visit } } as GlobalEvent<'start'>);
    });
    return visit;
}

function finish(visit: PendingVisit) {
    act(() => {
        handler('finish')({ detail: { visit } } as GlobalEvent<'finish'>);
    });
}

describe('useTabSkeleton', () => {
    const scrollTo = vi.fn();

    beforeEach(() => {
        vi.mocked(router.on).mockClear();
        scrollTo.mockClear();
        vi.stubGlobal('scrollTo', scrollTo);
        window.scrollY = 0;
    });

    afterEach(() => {
        const left = pendingTabSnapshot();
        if (left !== null) {
            finish(left.visit);
        }
        window.scrollY = 0;
    });

    it('is null while no tab visit is pending', () => {
        const { result } = renderHook(() => useTabSkeleton('Home'));

        expect(result.current).toBeNull();
    });

    it('returns the pending tab while the tapped-from page is still shown', () => {
        const { result } = renderHook(() => useTabSkeleton('Home'));

        tap('plan', 'Home');

        expect(result.current).toMatchObject({ tab: 'plan', href: '/plan' });
    });

    it('yields to the destination page the moment it renders, before the visit finishes', () => {
        const { result, rerender } = renderHook(
            ({ component }) => useTabSkeleton(component),
            { initialProps: { component: 'Home' } },
        );

        tap('trends', 'Home');
        rerender({ component: 'Trends' });

        expect(result.current).toBeNull();
    });

    it('restores the current page when the visit ends without arriving', () => {
        const { result } = renderHook(() => useTabSkeleton('Home'));

        const visit = tap('plan', 'Home');
        finish(visit);

        expect(result.current).toBeNull();
    });

    it('scrolls to the top when the skeleton shows', () => {
        window.scrollY = 640;
        renderHook(() => useTabSkeleton('Home'));

        tap('plan', 'Home');

        expect(scrollTo).toHaveBeenCalledExactlyOnceWith(0, 0);
    });

    it('puts the scroll back when the visit ends on the same page', () => {
        window.scrollY = 640;
        renderHook(() => useTabSkeleton('Home'));

        const visit = tap('plan', 'Home');
        window.scrollY = 0;
        finish(visit);

        expect(scrollTo).toHaveBeenLastCalledWith(0, 640);
    });

    it('restores the scroll read before the hidden page let the document shrink', () => {
        window.scrollY = 640;
        renderHook(() => useTabSkeleton('Home'));

        const visit = tabVisit('/plan');
        act(() => {
            armPendingTab('plan', 'Home');
            handler('start')({ detail: { visit } } as GlobalEvent<'start'>);
            window.scrollY = 0;
        });
        finish(visit);

        expect(scrollTo).toHaveBeenLastCalledWith(0, 640);
    });

    it('leaves the scroll to Inertia once the new page has landed', () => {
        window.scrollY = 640;
        const { rerender } = renderHook(
            ({ component }) => useTabSkeleton(component),
            { initialProps: { component: 'Home' } },
        );

        const visit = tap('plan', 'Home');
        rerender({ component: 'Plan' });
        finish(visit);

        expect(scrollTo).toHaveBeenCalledExactlyOnceWith(0, 0);
    });

    it('keeps the first scroll position across an interrupting second tap', () => {
        window.scrollY = 640;
        renderHook(() => useTabSkeleton('Home'));

        const first = tap('plan', 'Home');
        window.scrollY = 0;
        const second = tabVisit('/trends');
        act(() => {
            armPendingTab('trends', 'Home');
            handler('finish')({
                detail: { visit: first },
            } as GlobalEvent<'finish'>);
            handler('start')({
                detail: { visit: second },
            } as GlobalEvent<'start'>);
        });
        finish(second);

        expect(scrollTo).toHaveBeenLastCalledWith(0, 640);
    });

    it('stops listening to the router on unmount', () => {
        const off = vi.fn();
        vi.mocked(router.on).mockReturnValue(off);

        const { unmount } = renderHook(() => useTabSkeleton('Home'));
        unmount();

        expect(off).toHaveBeenCalledTimes(2);
        vi.mocked(router.on)
            .mockReset()
            .mockImplementation(() => vi.fn());
    });
});
