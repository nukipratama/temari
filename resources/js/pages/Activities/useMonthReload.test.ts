import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import useMonthReload from './useMonthReload';

const PROPS = ['month', 'cells'];

function handler<T extends 'start' | 'finish'>(name: T) {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as (event: GlobalEvent<T>) => void;
}

function visit(only: string[]) {
    return { only } as PendingVisit;
}

function start(pending: PendingVisit) {
    act(() => {
        handler('start')({
            detail: { visit: pending },
        } as GlobalEvent<'start'>);
    });
}

function finish(done: PendingVisit) {
    act(() => {
        handler('finish')({ detail: { visit: done } } as GlobalEvent<'finish'>);
    });
}

describe('useMonthReload', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
    });

    it('is idle until a visit starts', () => {
        const { result } = renderHook(() => useMonthReload(PROPS));

        expect(result.current).toBe(false);
    });

    it('is true from the start of a visit reloading every prop until it finishes', () => {
        const { result } = renderHook(() => useMonthReload(PROPS));
        const month = visit(['month', 'cells', 'monthLabel']);

        start(month);

        expect(result.current).toBe(true);

        finish(month);

        expect(result.current).toBe(false);
    });

    it.each([
        ['a visit with no partial list', []],
        ['a poll of one prop', ['cells']],
        ['an unrelated reload', ['narration']],
    ])('ignores %s', (_, only) => {
        const { result } = renderHook(() => useMonthReload(PROPS));
        const other = visit(only);

        start(other);

        expect(result.current).toBe(false);
    });

    it('stays true when an unrelated visit finishes mid-reload', () => {
        const { result } = renderHook(() => useMonthReload(PROPS));

        start(visit(['month', 'cells']));
        finish(visit(['narration']));

        expect(result.current).toBe(true);
    });

    it('removes both router listeners on unmount', () => {
        const offStart = vi.fn();
        const offFinish = vi.fn();
        vi.mocked(router.on)
            .mockReturnValueOnce(offStart)
            .mockReturnValueOnce(offFinish);

        renderHook(() => useMonthReload(PROPS)).unmount();

        expect(offStart).toHaveBeenCalledOnce();
        expect(offFinish).toHaveBeenCalledOnce();
    });
});
