import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { writeContextualOrigin } from '@/lib/navigationMemory';

import {
    prefersReducedMotion,
    slideHistoryTraversals,
    slidesScreens,
    startSlide,
    visitDirection,
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

describe('visitDirection', () => {
    beforeEach(() => {
        setMedia();
        window.history.replaceState(null, '', '/plan');
        window.sessionStorage.clear();
    });

    it('slides the bell from a tab into Inbox', () => {
        expect(visitDirection('Plan', new URL('http://localhost/inbox'))).toBe(
            'push',
        );
    });

    it('pops a run back to the tab it remembers as its origin', () => {
        window.history.replaceState(null, '', '/activities/12');
        writeContextualOrigin({ href: '/plan', scrollY: 0, tab: 'plan' });

        expect(
            visitDirection('Runs/Show', new URL('http://localhost/plan')),
        ).toBe('pop');
    });

    it('leaves a reload of the screen on show alone', () => {
        window.history.replaceState(null, '', '/inbox');

        expect(
            visitDirection('Inbox', new URL('http://localhost/inbox?page=2')),
        ).toBeNull();
    });

    it('leaves every visit alone under a fine pointer', () => {
        setMedia({ coarse: false });

        expect(
            visitDirection('Plan', new URL('http://localhost/inbox')),
        ).toBeNull();
    });
});

const ENCRYPTED = { page: 'encrypted' };

function trackerNavigate(): () => void {
    const call = vi
        .mocked(router.on)
        .mock.calls.find(([event]) => event === 'navigate');
    if (!call) {
        throw new Error('router.on was never called for "navigate"');
    }
    return call[1] as () => void;
}

function setHistoryIndex(index: number | null): void {
    vi.stubGlobal(
        'navigation',
        index === null ? undefined : { currentEntry: { index } },
    );
}

describe('slideHistoryTraversals', () => {
    let component = 'Runs/Show';
    let untrack: () => void;
    let inertia = vi.fn<(event: PopStateEvent) => void>();

    function showPage(
        path: string,
        shown: string,
        index: number | null = null,
    ) {
        window.history.replaceState(null, '', path);
        setHistoryIndex(index);
        component = shown;
        trackerNavigate()();
    }

    beforeEach(() => {
        setMedia();
        window.sessionStorage.clear();
        vi.mocked(router.on).mockClear();
        inertia = vi.fn();
        untrack = slideHistoryTraversals(() => component);
        showPage('/activities/12', 'Runs/Show');
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
        {
            state = ENCRYPTED as unknown,
            index = null as number | null,
            animatedByBrowser = false,
        } = {},
    ) {
        window.history.replaceState(null, '', path);
        setHistoryIndex(index);
        const event = new PopStateEvent('popstate', { state });
        Object.defineProperty(event, 'hasUAVisualTransition', {
            value: animatedByBrowser,
        });
        window.dispatchEvent(event);
    }

    it('holds Back to the back target until the slide has captured the old screen, then replays it', async () => {
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

    it('pops Back from a pushed screen to wherever it was opened from', () => {
        fakeViewTransitions();
        showPage('/inbox', 'Inbox', 4);

        popTo('/plan', { index: 3 });

        expect(document.documentElement.dataset.navSlide).toBe('pop');
    });

    it('pushes Forward into a pushed screen', () => {
        fakeViewTransitions();
        showPage('/plan', 'Plan', 3);

        popTo('/inbox', { index: 4 });

        expect(document.documentElement.dataset.navSlide).toBe('push');
    });

    it.each([
        ['Back between tabs', '/plan', 'Plan', 4, '/', 3],
        ['Forward onto a tab', '/inbox', 'Inbox', 3, '/plan', 4],
    ])('keeps the fade for %s', (_, from, shown, at, to, index) => {
        fakeViewTransitions();
        showPage(from, shown, at);

        popTo(to, { index });

        expect(inertia).toHaveBeenCalledOnce();
        expect(document.startViewTransition).not.toHaveBeenCalled();
    });

    it('slides only a return to the back target without the Navigation API', () => {
        fakeViewTransitions();
        showPage('/inbox', 'Inbox');

        popTo('/plan');

        expect(document.startViewTransition).not.toHaveBeenCalled();
    });

    it.each([
        [
            'an overlay closing on the same page',
            '/activities/12',
            ENCRYPTED,
            false,
        ],
        ['a state with no Inertia page', '/history', null, false],
        ['a gesture the browser already animated', '/history', ENCRYPTED, true],
    ])('passes %s straight to Inertia', (_, path, state, animatedByBrowser) => {
        fakeViewTransitions();

        popTo(path, { state, animatedByBrowser });

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
        const offNavigate = vi.fn();
        vi.mocked(router.on).mockReturnValueOnce(offNavigate);
        fakeViewTransitions();
        untrack();
        slideHistoryTraversals(() => component)();

        popTo('/history');

        expect(offNavigate).toHaveBeenCalledOnce();
        expect(inertia).toHaveBeenCalledOnce();
    });
});
