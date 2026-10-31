import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { writeContextualOrigin } from '@/lib/navigationMemory';

import {
    armEffortHint,
    armPendingTab,
    pendingTabSnapshot,
    subscribePendingTab,
    trackTabVisits,
} from './pendingTab';

function handler<T extends 'start' | 'finish'>(name: T) {
    const call = [...vi.mocked(router.on).mock.calls]
        .reverse()
        .find(([event]) => event === name);
    if (!call) {
        throw new Error(`router.on was never called for "${name}"`);
    }
    return call[1] as (event: GlobalEvent<T>) => void;
}

function visit(href: string, overrides: Partial<PendingVisit> = {}) {
    return {
        url: new URL(href, 'http://localhost'),
        method: 'get',
        async: false,
        prefetch: false,
        ...overrides,
    } as PendingVisit;
}

function start(pending: PendingVisit) {
    handler('start')({ detail: { visit: pending } } as GlobalEvent<'start'>);
}

function finish(done: PendingVisit) {
    handler('finish')({ detail: { visit: done } } as GlobalEvent<'finish'>);
}

let currentComponent = 'Home';

describe('pendingTab', () => {
    let untrack: () => void;

    beforeEach(() => {
        currentComponent = 'Home';
        vi.mocked(router.on).mockClear();
        untrack = trackTabVisits(() => currentComponent);
    });

    afterEach(() => {
        const left = pendingTabSnapshot();
        if (left !== null) {
            finish(left.visit as PendingVisit);
        }
        start(visit('/settings'));
        untrack();
    });

    it('is idle until a tab is tapped', () => {
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('marks the tapped tab pending when its visit starts', () => {
        window.scrollY = 320;
        armPendingTab('plan', 'Home');
        const planVisit = visit('/plan?day=2026-10-12');
        start(planVisit);

        expect(pendingTabSnapshot()).toEqual({
            tab: 'plan',
            screen: null,
            from: 'Home',
            href: '/plan?day=2026-10-12',
            scrollY: 320,
            visit: planVisit,
        });
    });

    it('stays idle when a visit starts without a tab tap', () => {
        start(visit('/history'));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('ignores a visit to a different place than the tapped tab', () => {
        armPendingTab('plan', 'Home');
        start(visit('/runs/12'));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('forgets a tap whose visit never started once another visit does', () => {
        armPendingTab('history', 'Home');
        start(visit('/inbox'));
        start(visit('/history'));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it.each([
        ['an async partial reload', { async: true }],
        ['a prefetch', { prefetch: true }],
    ])('neither consumes the tap nor shows on %s', (_, overrides) => {
        armPendingTab('trends', 'Home');
        start(visit('/trends', overrides));

        expect(pendingTabSnapshot()).toBeNull();

        start(visit('/trends'));

        expect(pendingTabSnapshot()?.tab).toBe('trends');
    });

    it('ignores a non-GET visit to the tab path', () => {
        armPendingTab('plan', 'Home');
        start(visit('/plan', { method: 'post' }));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('clears when its own visit finishes, however it ended', () => {
        armPendingTab('trends', 'Home');
        const trendsVisit = visit('/trends');
        start(trendsVisit);
        finish(trendsVisit);

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('stays pending when some other visit finishes', () => {
        armPendingTab('trends', 'Home');
        start(visit('/trends'));
        finish(visit('/', { async: true }));

        expect(pendingTabSnapshot()?.tab).toBe('trends');
    });

    it('hands over to a second tap that interrupts the first', () => {
        armPendingTab('plan', 'Home');
        const planVisit = visit('/plan');
        start(planVisit);

        armPendingTab('history', 'Home');
        finish(planVisit);
        const historyVisit = visit('/history?view=calendar');
        start(historyVisit);

        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'history',
            href: '/history?view=calendar',
        });

        finish(historyVisit);

        expect(pendingTabSnapshot()).toBeNull();
    });

    it.each(['Home', 'Plan', 'Trends', 'Profile'])(
        'marks a link into /race pending from %s, under the plan tab',
        (from) => {
            currentComponent = from;
            window.scrollY = 120;
            const raceVisit = visit('/race');
            start(raceVisit);

            expect(pendingTabSnapshot()).toEqual({
                tab: 'plan',
                screen: null,
                from,
                href: '/race',
                scrollY: 120,
                visit: raceVisit,
            });
        },
    );

    it('does not mark a visit to /race pending from Race itself', () => {
        currentComponent = 'Race';
        start(visit('/race'));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it.each([
        ['an async partial reload', { async: true }],
        ['a prefetch', { prefetch: true }],
        ['a non-GET visit', { method: 'delete' as const }],
    ])('does not mark %s of /race pending', (_, overrides) => {
        start(visit('/race', overrides));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('marks the plan tab tapped while it remembers /race as a visit into /race', () => {
        armPendingTab('plan', 'Trends');
        start(visit('/race'));

        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'plan',
            from: 'Trends',
            href: '/race',
        });
    });

    it('marks a history toggle pending with its query kept', () => {
        armPendingTab('history', 'History');
        start(visit('/history?view=calendar'));

        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'history',
            from: 'History',
            href: '/history?view=calendar',
        });
    });

    it('notifies subscribers on every change and stops after unsubscribing', () => {
        const listener = vi.fn();
        const unsubscribe = subscribePendingTab(listener);

        armPendingTab('plan', 'Home');
        const planVisit = visit('/plan');
        start(planVisit);
        finish(planVisit);

        expect(listener).toHaveBeenCalledTimes(2);

        unsubscribe();
        armPendingTab('plan', 'Home');
        start(visit('/plan'));

        expect(listener).toHaveBeenCalledTimes(2);
    });

    it('forgets the pending tab and the tap when untracked before its visit finishes', () => {
        armPendingTab('plan', 'Home');
        start(visit('/plan'));
        armPendingTab('trends', 'Home');

        untrack();
        untrack = trackTabVisits(() => currentComponent);

        expect(pendingTabSnapshot()).toBeNull();

        start(visit('/trends'));

        expect(pendingTabSnapshot()).toBeNull();
    });

    it('removes both router listeners when untracked', () => {
        const offStart = vi.fn();
        const offFinish = vi.fn();
        vi.mocked(router.on)
            .mockReturnValueOnce(offStart)
            .mockReturnValueOnce(offFinish);

        trackTabVisits(() => currentComponent)();

        expect(offStart).toHaveBeenCalledOnce();
        expect(offFinish).toHaveBeenCalledOnce();
    });
});

function onPhone({ reduced = false } = {}): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: query.includes('coarse') ? true : reduced,
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

describe('pendingTab pushes and pops on a phone', () => {
    let untrack: () => void;

    beforeEach(() => {
        currentComponent = 'History';
        window.history.replaceState(null, '', '/history');
        onPhone();
        vi.mocked(router.on).mockClear();
        untrack = trackTabVisits(() => currentComponent);
    });

    afterEach(() => {
        untrack();
        Reflect.deleteProperty(document, 'startViewTransition');
        delete document.documentElement.dataset.navSlide;
    });

    it('slides a run in on the tap and shows its screen pending once the slide has the old frame', () => {
        const slide = fakeSlide();
        window.history.replaceState(null, '', '/history?view=calendar');
        window.scrollY = 640;
        const runVisit = visit('/activities/12');

        start(runVisit);

        expect(document.documentElement.dataset.navSlide).toBe('push');
        expect(pendingTabSnapshot()).toBeNull();

        slide.update();

        expect(pendingTabSnapshot()).toEqual({
            tab: null,
            screen: 'Runs/Show',
            origin: {
                href: '/history?view=calendar',
                scrollY: 640,
                tab: 'history',
            },
            effortSaved: null,
            from: 'History',
            href: '/activities/12',
            scrollY: 640,
            visit: runVisit,
        });
    });

    it.each([
        [true, true],
        [false, false],
        [undefined, null],
    ])(
        "carries the tapped row's effort hint (%s) into the run's skeleton",
        (saved, effortSaved) => {
            const slide = fakeSlide();
            armEffortHint(saved);

            start(visit('/activities/12'));
            slide.update();

            expect(pendingTabSnapshot()).toMatchObject({ effortSaved });
        },
    );

    it('spends the hint on the next visit, so a later run opens without one', () => {
        const slide = fakeSlide();
        armEffortHint(true);
        const inboxVisit = visit('/inbox', { completed: true });
        start(inboxVisit);
        slide.update();
        finish(inboxVisit);

        start(visit('/activities/12'));
        slide.update();

        expect(pendingTabSnapshot()).toMatchObject({ effortSaved: null });
    });

    it('carries the remembered origin into a run pushed from a run', () => {
        const slide = fakeSlide();
        currentComponent = 'Runs/Show';
        window.history.replaceState(null, '', '/activities/12');
        const origin = { href: '/plan', scrollY: 80, tab: 'plan' as const };
        writeContextualOrigin(origin);

        start(visit('/activities/9'));
        slide.update();

        expect(pendingTabSnapshot()).toMatchObject({
            screen: 'Runs/Show',
            origin,
        });
        writeContextualOrigin(null);
    });

    it('pushes Inbox from any tab, the bell from Plan included', () => {
        const slide = fakeSlide();
        currentComponent = 'Plan';

        start(visit('/inbox'));
        slide.update();

        expect(document.documentElement.dataset.navSlide).toBe('push');
        expect(pendingTabSnapshot()?.screen).toBe('Inbox');
    });

    it('pops back to a tab under that tab, with its skeleton', () => {
        const slide = fakeSlide();
        currentComponent = 'Runs/Show';
        window.history.replaceState(null, '', '/activities/12');

        start(visit('/history?view=calendar'));
        slide.update();

        expect(document.documentElement.dataset.navSlide).toBe('pop');
        expect(pendingTabSnapshot()).toMatchObject({
            tab: 'history',
            screen: null,
            href: '/history?view=calendar',
        });
    });

    it('pops Settings back to Profile as a pushed screen', () => {
        const slide = fakeSlide();
        currentComponent = 'Settings/Index';

        start(visit('/profile'));
        slide.update();

        expect(pendingTabSnapshot()).toMatchObject({
            tab: null,
            screen: 'Profile',
        });
    });

    it('shows the pushed screen at once, without a slide, under reduced motion', () => {
        fakeSlide();
        onPhone({ reduced: true });
        currentComponent = 'Home';

        start(visit('/inbox'));

        expect(document.startViewTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()?.screen).toBe('Inbox');
    });

    it('leaves a push alone under a fine pointer', () => {
        fakeSlide();
        vi.unstubAllGlobals();

        start(visit('/activities/12'));

        expect(document.startViewTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('skips the slide and hands the page back when the visit is cancelled', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: false });
        start(runVisit);
        slide.update();

        finish(Object.assign(runVisit, { cancelled: true }));

        expect(slide.skipTransition).toHaveBeenCalledOnce();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('lets the slide play out when the page arrives during it', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        start(runVisit);
        slide.update();
        window.history.replaceState(null, '', '/activities/12');

        finish(runVisit);

        expect(slide.skipTransition).not.toHaveBeenCalled();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('skips the slide when the visit ends without leaving the page, as on a network error', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        start(runVisit);
        slide.update();

        finish(runVisit);

        expect(slide.skipTransition).toHaveBeenCalledOnce();
        expect(pendingTabSnapshot()).toBeNull();
    });

    it('never shows a skeleton for a visit that ended before the slide updated', () => {
        const slide = fakeSlide();
        const runVisit = visit('/activities/12', { completed: true });
        start(runVisit);
        finish(runVisit);

        slide.update();

        expect(pendingTabSnapshot()).toBeNull();
    });
});
