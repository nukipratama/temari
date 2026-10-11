import type { GlobalEvent, PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
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
