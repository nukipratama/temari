import { router } from '@inertiajs/react';
import { act, cleanup, renderHook } from '@testing-library/react';
import {
    afterEach,
    beforeAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import { useOverlayHistory } from './useOverlayHistory';

type StartHandler = (event: {
    detail: { visit: { preserveState: unknown; prefetch: boolean } };
}) => void;

const handlers: { start?: StartHandler } = {};
const inertiaPopstate = vi.fn();

beforeAll(() => {
    vi.mocked(router.on).mockImplementation(((
        event: string,
        callback: StartHandler,
    ) => {
        if (event === 'start') handlers.start = callback;
        return () => {};
    }) as unknown as typeof router.on);
    // Registered before any overlay, like Inertia's own listener.
    window.addEventListener('popstate', inertiaPopstate);
});

beforeEach(() => {
    // A page entry to go Back from, above whatever the last test left behind.
    window.history.pushState({ page: 'current' }, '');
});

afterEach(async () => {
    cleanup();
    await settle();
    inertiaPopstate.mockClear();
});

/** Lets jsdom's queued history traversals fire their popstate. */
async function settle() {
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
}

async function back() {
    window.history.back();
    await settle();
}

function overlay(initiallyOpen = true) {
    const onClose = vi.fn();
    const hook = renderHook(
        ({ open }: { open: boolean }) => useOverlayHistory(open, onClose),
        { initialProps: { open: initiallyOpen } },
    );

    return {
        onClose,
        close: () => hook.rerender({ open: false }),
        unmount: hook.unmount,
    };
}

describe('useOverlayHistory', () => {
    it('pushes nothing while closed', () => {
        const before = window.history.length;
        overlay(false);

        expect(window.history.length).toBe(before);
    });

    it('closes the overlay on Back without the page seeing it', async () => {
        const first = overlay();

        await back();

        expect(first.onClose).toHaveBeenCalledOnce();
        expect(inertiaPopstate).not.toHaveBeenCalled();
        first.close();
    });

    it('pops its own entry when closed another way, so the next Back is the page’s', async () => {
        const sheet = overlay();

        sheet.close();
        await settle();

        expect(inertiaPopstate).not.toHaveBeenCalled();

        await back();

        expect(inertiaPopstate).toHaveBeenCalledOnce();
        expect(sheet.onClose).not.toHaveBeenCalled();
    });

    it('pops its entry on unmount while open', async () => {
        const sheet = overlay();

        sheet.unmount();
        await settle();
        await back();

        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('closes stacked overlays one per Back, topmost first', async () => {
        const lower = overlay();
        const upper = overlay();

        await back();
        expect(upper.onClose).toHaveBeenCalledOnce();
        expect(lower.onClose).not.toHaveBeenCalled();
        upper.close();

        await back();
        expect(lower.onClose).toHaveBeenCalledOnce();
        lower.close();

        expect(inertiaPopstate).not.toHaveBeenCalled();
        await back();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('drops a lower overlay’s entry along with the upper one once both are closed', async () => {
        const lower = overlay();
        const upper = overlay();

        lower.close();
        await settle();
        await back();

        expect(upper.onClose).toHaveBeenCalledOnce();
        upper.close();
        await settle();
        expect(inertiaPopstate).not.toHaveBeenCalled();

        await back();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('keeps the page state a same-URL visit wrote into the overlay’s entry', async () => {
        window.history.replaceState({ page: 'before' }, '');
        const sheet = overlay();
        window.history.replaceState({ page: 'after' }, '');

        sheet.close();
        await settle();

        expect(window.history.state).toEqual({ page: 'after' });
    });

    it('closes every overlay and pops their entries when a visit replaces the page', async () => {
        const lower = overlay();
        const upper = overlay();

        act(() =>
            handlers.start?.({
                detail: { visit: { preserveState: false, prefetch: false } },
            }),
        );
        await settle();

        expect(upper.onClose).toHaveBeenCalledOnce();
        expect(lower.onClose).toHaveBeenCalledOnce();
        expect(inertiaPopstate).not.toHaveBeenCalled();
        upper.close();
        lower.close();

        await back();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('leaves overlays open through a visit that preserves state', async () => {
        const sheet = overlay();

        act(() =>
            handlers.start?.({
                detail: { visit: { preserveState: true, prefetch: false } },
            }),
        );
        act(() =>
            handlers.start?.({
                detail: { visit: { preserveState: false, prefetch: true } },
            }),
        );
        await settle();

        expect(sheet.onClose).not.toHaveBeenCalled();
        await back();
        expect(sheet.onClose).toHaveBeenCalledOnce();
        sheet.close();
    });

    it('ignores visits with no overlay open', async () => {
        act(() =>
            handlers.start?.({
                detail: { visit: { preserveState: false, prefetch: false } },
            }),
        );
        await settle();

        expect(inertiaPopstate).not.toHaveBeenCalled();
    });
});
