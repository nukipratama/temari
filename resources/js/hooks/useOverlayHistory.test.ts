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

import { back, settle } from '@/test/overlayHistory';

import { useOverlayHistory } from './useOverlayHistory';

type StartHandler = (event: {
    detail: { visit: { preserveState: unknown; prefetch: boolean } };
}) => void;

const inertiaPopstate = vi.fn();
let onVisitStart: StartHandler;
let onBeforeUpdate: (event: { detail: { page: { url: string } } }) => void;
let onVisitSuccess: () => void;

function inertiaHandler(name: string) {
    return vi
        .mocked(router.on)
        .mock.calls.find(([event]) => event === name)?.[1];
}

beforeAll(() => {
    // test/setup.ts installed the manager; this stands in for Inertia's own listener, registered after it.
    onVisitStart = inertiaHandler('start') as StartHandler;
    onBeforeUpdate = inertiaHandler('beforeUpdate') as typeof onBeforeUpdate;
    onVisitSuccess = inertiaHandler('success') as typeof onVisitSuccess;
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

    it('keeps the page state a background reload wrote while the overlay closes on Back', async () => {
        const sheet = overlay();
        window.history.replaceState({ page: 'reloaded' }, '');
        onVisitSuccess();

        await back();

        expect(sheet.onClose).toHaveBeenCalledOnce();
        expect(window.history.state).toEqual({ page: 'reloaded' });
        sheet.close();
    });

    it('leaves the pushed page in place when a state-preserving visit lands on another URL', async () => {
        const sheet = overlay();

        act(() => onBeforeUpdate({ detail: { page: { url: '/login' } } }));
        window.history.pushState({ page: 'login' }, '', '/login');
        sheet.unmount();
        await settle();

        expect(sheet.onClose).toHaveBeenCalledOnce();
        expect(window.location.pathname).toBe('/login');
        expect(window.history.state).toEqual({ page: 'login' });
        expect(inertiaPopstate).not.toHaveBeenCalled();
    });

    it('keeps overlays open through a same-URL page update', async () => {
        const sheet = overlay();

        act(() =>
            onBeforeUpdate({
                detail: { page: { url: window.location.pathname } },
            }),
        );
        await back();

        expect(sheet.onClose).toHaveBeenCalledOnce();
        expect(inertiaPopstate).not.toHaveBeenCalled();
        sheet.close();
    });

    it('closes every overlay and pops their entries when a visit replaces the page', async () => {
        const lower = overlay();
        const upper = overlay();

        act(() =>
            onVisitStart({
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
            onVisitStart({
                detail: { visit: { preserveState: true, prefetch: false } },
            }),
        );
        act(() =>
            onVisitStart({
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
            onVisitStart({
                detail: { visit: { preserveState: false, prefetch: false } },
            }),
        );
        onBeforeUpdate({ detail: { page: { url: '/elsewhere' } } });
        onVisitSuccess();
        await settle();

        expect(inertiaPopstate).not.toHaveBeenCalled();
    });
});
