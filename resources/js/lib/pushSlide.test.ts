import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    prefersReducedMotion,
    slideHistoryTraversals,
    slidesScreens,
    startSlide,
} from './pushSlide';

function setMedia({ coarse = true, reduced = false } = {}): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: query.includes('coarse') ? coarse : reduced,
        })),
    );
}

interface FakeTransition {
    update: () => unknown;
    skipTransition: ReturnType<typeof vi.fn>;
    end: () => Promise<void>;
}

function fakeViewTransitions(): FakeTransition[] {
    const started: FakeTransition[] = [];
    document.startViewTransition = vi.fn((update: () => unknown) => {
        let end = () => undefined as void;
        const finished = new Promise<void>((resolve) => {
            end = resolve;
        });
        const entry: FakeTransition = {
            update,
            skipTransition: vi.fn(),
            end: async () => {
                end();
                await finished;
                await Promise.resolve();
            },
        };
        started.push(entry);

        return {
            ready: Promise.resolve(),
            finished,
            updateCallbackDone: Promise.resolve(),
            skipTransition: entry.skipTransition,
        } as unknown as ViewTransition;
    }) as unknown as typeof document.startViewTransition;

    return started;
}

function handlerFor(name: 'navigate' | 'start') {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as () => void;
}

describe('pushSlide', () => {
    afterEach(() => {
        Reflect.deleteProperty(document, 'startViewTransition');
        delete document.documentElement.dataset.navSlide;
    });

    it('slides screens only under a coarse pointer', () => {
        setMedia({ coarse: true });
        expect(slidesScreens()).toBe(true);

        setMedia({ coarse: false });
        expect(slidesScreens()).toBe(false);
    });

    it('reads reduced motion at call time', () => {
        setMedia({ reduced: true });
        expect(prefersReducedMotion()).toBe(true);

        setMedia({ reduced: false });
        expect(prefersReducedMotion()).toBe(false);
    });

    it('treats a browser without matchMedia as a desktop with motion', () => {
        vi.stubGlobal('matchMedia', undefined);

        expect(slidesScreens()).toBe(false);
        expect(prefersReducedMotion()).toBe(false);
    });

    it('marks the direction on the root for the length of the transition', async () => {
        const started = fakeViewTransitions();
        const update = vi.fn();

        startSlide('push', update);

        expect(document.documentElement.dataset.navSlide).toBe('push');
        started[0]?.update();
        expect(update).toHaveBeenCalledOnce();

        await started[0]?.end();

        expect(document.documentElement.dataset.navSlide).toBeUndefined();
    });

    it('leaves the mark to a newer slide that started before the older one ended', async () => {
        const started = fakeViewTransitions();

        startSlide('push', vi.fn());
        startSlide('pop', vi.fn());
        await started[0]?.end();

        expect(document.documentElement.dataset.navSlide).toBe('pop');
    });

    it('updates at once where the browser has no view transitions', () => {
        const update = vi.fn();

        expect(startSlide('push', update)).toBeNull();
        expect(update).toHaveBeenCalledOnce();
        expect(document.documentElement.dataset.navSlide).toBeUndefined();
    });

    it('updates at once on a hidden tab', () => {
        fakeViewTransitions();
        const hidden = vi
            .spyOn(document, 'visibilityState', 'get')
            .mockReturnValue('hidden');
        const update = vi.fn();

        expect(startSlide('pop', update)).toBeNull();
        expect(update).toHaveBeenCalledOnce();
        hidden.mockRestore();
    });
});

const ENCRYPTED = { page: 'encrypted' };

describe('slideHistoryTraversals', () => {
    let component = 'Runs/Show';
    let untrack: () => void;
    let inertia = vi.fn<(event: PopStateEvent) => void>();

    beforeEach(() => {
        component = 'Runs/Show';
        setMedia();
        vi.mocked(router.on).mockClear();
        inertia = vi.fn();
        untrack = slideHistoryTraversals(() => component);
        window.addEventListener('popstate', inertia);
    });

    afterEach(() => {
        window.removeEventListener('popstate', inertia);
        untrack();
        Reflect.deleteProperty(document, 'startViewTransition');
        delete document.documentElement.dataset.navSlide;
    });

    function popTo(
        path: string,
        state: unknown = ENCRYPTED,
        animatedByBrowser = false,
    ) {
        window.history.replaceState(null, '', path);
        const event = new PopStateEvent('popstate', { state });
        Object.defineProperty(event, 'hasUAVisualTransition', {
            value: animatedByBrowser,
        });
        window.dispatchEvent(event);
    }

    it('holds Back to a parent until the slide has captured the old screen, then replays it', async () => {
        const started = fakeViewTransitions();

        popTo('/history');

        expect(inertia).not.toHaveBeenCalled();
        expect(document.documentElement.dataset.navSlide).toBe('pop');

        const swapped = started[0]?.update() as Promise<void>;

        expect(inertia).toHaveBeenCalledOnce();
        expect(inertia.mock.calls[0]?.[0].state).toBe(ENCRYPTED);

        handlerFor('navigate')();
        await expect(swapped).resolves.toBeUndefined();
    });

    it('lets go of the transition if Inertia reloads the page instead', async () => {
        const started = fakeViewTransitions();

        popTo('/history');
        const swapped = started[0]?.update() as Promise<void>;
        handlerFor('start')();

        await expect(swapped).resolves.toBeUndefined();
    });

    it('slides Forward back into a pushed screen as a push', () => {
        fakeViewTransitions();
        component = 'Home';

        popTo('/inbox');

        expect(document.documentElement.dataset.navSlide).toBe('push');
    });

    it.each([
        ['a traversal between unrelated screens', '/inbox', ENCRYPTED, false],
        ['a state with no Inertia page', '/history', null, false],
        ['a gesture the browser already animated', '/history', ENCRYPTED, true],
    ])('passes %s straight to Inertia', (_, path, state, animatedByBrowser) => {
        fakeViewTransitions();

        popTo(path, state, animatedByBrowser);

        expect(inertia).toHaveBeenCalledOnce();
        expect(document.startViewTransition).not.toHaveBeenCalled();
    });

    it.each([
        ['a fine pointer', { coarse: false }],
        ['reduced motion', { reduced: true }],
    ])('passes Back straight to Inertia under %s', (_, media) => {
        fakeViewTransitions();
        setMedia(media);

        popTo('/history');

        expect(inertia).toHaveBeenCalledOnce();
        expect(document.startViewTransition).not.toHaveBeenCalled();
    });

    it('stops listening once untracked', () => {
        fakeViewTransitions();
        untrack();

        popTo('/history');

        expect(inertia).toHaveBeenCalledOnce();
    });
});
