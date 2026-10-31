import type { PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { writeContextualOrigin } from '@/lib/navigationMemory';
import { pendingTabSnapshot, trackTabVisits } from '@/lib/pendingTab';

import { startPushStack } from './pushStack';

type Handler = (event: { detail: { visit: unknown } }) => void;

function fire(name: 'start' | 'finish', visit: unknown): void {
    vi.mocked(router.on)
        .mock.calls.filter(([event]) => event === name)
        .forEach(([, handler]) => (handler as Handler)({ detail: { visit } }));
}

function visit(href: string, overrides: Partial<PendingVisit> = {}) {
    return {
        url: new URL(href, 'http://localhost'),
        method: 'get',
        async: false,
        prefetch: false,
        showProgress: true,
        completed: false,
        ...overrides,
    } as PendingVisit;
}

function onPhone({ coarse = true, reduced = false } = {}): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: query.includes('coarse') ? coarse : reduced,
        })),
    );
}

function fakeSlide() {
    const slide = {
        update: () => undefined as unknown,
        skipTransition: vi.fn(),
    };
    document.startViewTransition = vi.fn((update: () => unknown) => {
        slide.update = update;
        return {
            ready: Promise.resolve(),
            finished: new Promise(() => undefined),
            skipTransition: slide.skipTransition,
        } as unknown as ViewTransition;
    }) as unknown as typeof document.startViewTransition;

    return slide;
}

function tapRow(effortSaved: string): void {
    const row = document.createElement('a');
    row.dataset.effortSaved = effortSaved;
    const inner = document.createElement('span');
    row.append(inner);
    document.body.append(row);
    inner.click();
    row.remove();
}

function pushedSkeleton(): HTMLElement {
    const pending = pendingTabSnapshot();
    if (!pending?.pushed) {
        throw new Error('no pushed screen is pending');
    }
    const { Skeleton } = pending.pushed;

    return render(<Skeleton />).container;
}

describe('startPushStack', () => {
    let component = 'History';
    let untrack: () => void;
    let stop: () => void;

    beforeEach(() => {
        component = 'History';
        window.history.replaceState(null, '', '/history');
        window.sessionStorage.clear();
        onPhone();
        vi.mocked(router.on).mockClear();
        untrack = trackTabVisits(() => component);
        stop = startPushStack(() => component);
    });

    afterEach(() => {
        stop();
        untrack();
        Reflect.deleteProperty(document, 'startViewTransition');
        delete document.documentElement.dataset.navSlide;
    });

    it('slides a run in on the tap and shows its screen once the slide has the old frame', () => {
        const slide = fakeSlide();
        window.scrollY = 640;
        const runVisit = visit('/activities/12');

        fire('start', runVisit);

        expect(document.documentElement.dataset.navSlide).toBe('push');
        expect(pendingTabSnapshot()).toBeNull();

        slide.update();

        expect(pendingTabSnapshot()).toMatchObject({
            tab: null,
            pushed: {
                screen: 'Runs/Show',
                origin: { href: '/history', scrollY: 640, tab: 'history' },
            },
            from: 'History',
            href: '/activities/12',
            scrollY: 640,
            visit: runVisit,
        });
    });

    it.each([
        ['saved', 'true', false],
        ['unsaved', 'false', true],
    ])(
        "draws the run skeleton from the tapped row's %s effort",
        (_, effortSaved, picker) => {
            const slide = fakeSlide();
            tapRow(effortSaved);

            fire('start', visit('/activities/12'));
            slide.update();

            expect(
                pushedSkeleton().querySelector('section .skeleton') !== null,
            ).toBe(picker);
        },
    );

    it('holds the effort picker for a run opened without a History row', () => {
        const slide = fakeSlide();

        fire('start', visit('/activities/12'));
        slide.update();

        expect(
            pushedSkeleton().querySelector('section .skeleton'),
        ).not.toBeNull();
    });

    it('spends the row hint on the next visit, so a later run opens without one', () => {
        const slide = fakeSlide();
        tapRow('true');
        const inboxVisit = visit('/inbox', { completed: true });
        fire('start', inboxVisit);
        slide.update();
        fire('finish', inboxVisit);

        fire('start', visit('/activities/12'));
        slide.update();

        expect(
            pushedSkeleton().querySelector('section .skeleton'),
        ).not.toBeNull();
    });

    it('carries the remembered origin into a run pushed from a run', () => {
        const slide = fakeSlide();
        component = 'Runs/Show';
        window.history.replaceState(null, '', '/activities/12');
        const origin = { href: '/plan', scrollY: 80, tab: 'plan' as const };
        writeContextualOrigin(origin);

        fire('start', visit('/activities/9'));
        slide.update();

        expect(pendingTabSnapshot()?.pushed?.origin).toEqual(origin);
        writeContextualOrigin(null);
    });

    it('pushes Inbox from any tab, the bell from Plan included', () => {
        const slide = fakeSlide();
        component = 'Plan';
        window.history.replaceState(null, '', '/plan');

        fire('start', visit('/inbox'));
        slide.update();

        expect(document.documentElement.dataset.navSlide).toBe('push');
        expect(pendingTabSnapshot()?.pushed?.screen).toBe('Inbox');
    });

    it('pops back to a tab under that tab, with its skeleton', () => {
        const slide = fakeSlide();
        component = 'Runs/Show';
        window.history.replaceState(null, '', '/activities/12');

        fire('start', visit('/history?view=calendar'));
        slide.update();

        expect(document.documentElement.dataset.navSlide).toBe('pop');
        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'history',
            href: '/history?view=calendar',
        });
        expect(pendingTabSnapshot()?.pushed).toBeUndefined();
    });

    it('pops Settings back to Profile as a pushed screen', () => {
        const slide = fakeSlide();
        component = 'Settings/Index';
        window.history.replaceState(null, '', '/settings');

        fire('start', visit('/profile'));
        slide.update();

        expect(pendingTabSnapshot()?.pushed?.screen).toBe('Profile');
        expect(pushedSkeleton()).toHaveTextContent('Profile');
    });

    it("takes over the Race skeleton's start, so the slide captures the run page", () => {
        const slide = fakeSlide();
        component = 'Runs/Show';
        window.history.replaceState(null, '', '/activities/12');
        writeContextualOrigin({ href: '/race', scrollY: 0, tab: 'plan' });

        fire('start', visit('/race'));

        expect(pendingTabSnapshot()).toBeNull();

        slide.update();

        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'plan',
            href: '/race',
        });
        writeContextualOrigin(null);
    });

    it('shows the pushed screen at once, without a slide, under reduced motion', () => {
        fakeSlide();
        onPhone({ reduced: true });
        component = 'Home';
        window.history.replaceState(null, '', '/');

        fire('start', visit('/inbox'));

        expect(document.startViewTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()?.pushed?.screen).toBe('Inbox');
    });

    it('leaves a push alone under a fine pointer', () => {
        fakeSlide();
        onPhone({ coarse: false });

        fire('start', visit('/activities/12'));

        expect(document.startViewTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it.each([
        ['an async reload', { async: true }],
        ['a prefetch', { prefetch: true }],
        ['a non-GET visit', { method: 'post' as const }],
    ])('leaves %s alone', (_, overrides) => {
        fakeSlide();

        fire('start', visit('/activities/12', overrides));

        expect(document.startViewTransition).not.toHaveBeenCalled();
    });

    it('skips the slide and hands the page back when the visit is cancelled', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12');
        fire('start', runVisit);
        slide.update();

        fire('finish', Object.assign(runVisit, { cancelled: true }));

        expect(slide.skipTransition).toHaveBeenCalledOnce();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('lets the slide play out when the page arrives during it', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        fire('start', runVisit);
        slide.update();
        window.history.replaceState(null, '', '/activities/12');

        fire('finish', runVisit);

        expect(slide.skipTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('skips the slide when the visit ends without leaving the page, as on a network error', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        fire('start', runVisit);
        slide.update();

        fire('finish', runVisit);

        expect(slide.skipTransition).toHaveBeenCalledOnce();
    });

    it('ignores the finish of a visit it did not slide', () => {
        const slide = fakeSlide();
        fire('start', visit('/activities/12'));

        fire('finish', visit('/plan'));

        expect(slide.skipTransition).not.toHaveBeenCalled();
    });

    it('never shows a skeleton for a visit that ended before the slide updated', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        fire('start', runVisit);
        fire('finish', runVisit);

        slide.update();

        expect(pendingTabSnapshot()).toBeNull();
    });

    it.each([
        ['a push it slides', '/activities/12', {}, false],
        ['a tab switch', '/plan', {}, true],
        ['a background reload', '/activities/12', { async: true }, true],
        [
            'a non-GET visit',
            '/activities/12',
            { method: 'post' as const },
            true,
        ],
    ])(
        'takes the granted cross-fade off only %s',
        (_, href, overrides, viewTransition) => {
            fakeSlide();
            const started = { ...visit(href, overrides), viewTransition: true };

            fire('start', started);

            expect(started.viewTransition).toBe(viewTransition);
        },
    );

    it('removes every listener it added once uninstalled', () => {
        const offs = [vi.fn(), vi.fn(), vi.fn()];
        offs.forEach((off) => vi.mocked(router.on).mockReturnValueOnce(off));
        const removeFromDocument = vi.spyOn(document, 'removeEventListener');
        const removeFromWindow = vi.spyOn(window, 'removeEventListener');

        startPushStack(() => component)();

        offs.forEach((off) => expect(off).toHaveBeenCalledOnce());
        expect(removeFromDocument).toHaveBeenCalledWith(
            'click',
            expect.any(Function),
            true,
        );
        expect(removeFromWindow).toHaveBeenCalledWith(
            'popstate',
            expect.any(Function),
            { capture: true },
        );
        removeFromDocument.mockRestore();
        removeFromWindow.mockRestore();
    });
});
