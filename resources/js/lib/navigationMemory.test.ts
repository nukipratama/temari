import type { GlobalEvent } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { NavigationPage } from './navigationMemory';

import {
    clearNavigationMemory,
    clearTabMemory,
    parseTabMemory,
    readContextualOrigin,
    readPlanSelectedDay,
    readTabMemory,
    rememberPlanSelectedDay,
    startContextualBackSession,
    subscribeToTabMemory,
    tabMemorySnapshot,
    writeContextualOrigin,
    writeTabMemory,
} from './navigationMemory';

function eventHandler(name: 'before' | 'navigate' | 'finish') {
    const call = vi
        .mocked(router.on)
        .mock.calls.find(([event]) => event === name);
    if (!call) throw new Error(`router.on was never called for "${name}"`);

    return call[1];
}

function fireBefore(url: string, only: string[] = []) {
    eventHandler('before')({
        detail: { visit: { url: new URL(url, window.location.origin), only } },
    } as GlobalEvent<'before'>);
}

function fireNavigate(page: NavigationPage) {
    eventHandler('navigate')({
        detail: { page },
    } as GlobalEvent<'navigate'>);
}

function fireFinish(only: string[]) {
    eventHandler('finish')({
        detail: { visit: { only } },
    } as GlobalEvent<'finish'>);
}

const PLAN_PAGE: NavigationPage = {
    component: 'Plan',
    url: '/plan?day=2026-06-16',
    props: { auth: { user: { id: 7 } } },
};

describe('navigationMemory', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        window.history.replaceState({}, '', '/');
        window.scrollY = 0;
        vi.mocked(router.on).mockClear();
    });

    afterEach(() => {
        window.sessionStorage.clear();
        window.history.replaceState({}, '', '/');
    });

    it('starts fresh when the app bootstraps', () => {
        writeContextualOrigin({
            href: '/plan',
            scrollY: 300,
            tab: 'plan',
        });
        writeTabMemory('plan', {
            href: '/plan?day=2026-06-16',
            scrollY: 312,
            selectedDay: '2026-06-16',
        });
        startContextualBackSession(PLAN_PAGE, router);

        expect(readContextualOrigin()).toBeNull();
        expect(readTabMemory('plan')).toBeNull();
    });

    it('captures the internal route and scroll when a tab opens a run', () => {
        window.history.replaceState({}, '', PLAN_PAGE.url);
        window.scrollY = 312;
        startContextualBackSession(PLAN_PAGE, router);

        fireBefore('/activities/42');
        fireNavigate({
            component: 'Runs/Show',
            url: '/activities/42',
            props: { auth: { user: { id: 7 } } },
        });

        expect(readContextualOrigin()).toEqual({
            href: '/plan?day=2026-06-16',
            scrollY: 312,
            tab: 'plan',
        });
        expect(readTabMemory('plan')).toEqual({
            href: '/plan?day=2026-06-16',
            scrollY: 312,
        });
    });

    it('does not capture an external target', () => {
        startContextualBackSession(PLAN_PAGE, router);

        fireBefore('https://example.com/activities/42');
        fireNavigate({
            component: 'Runs/Show',
            url: '/activities/42',
            props: { auth: { user: { id: 7 } } },
        });

        expect(readContextualOrigin()).toBeNull();
    });

    it('does not let deferred prop visits overwrite a saved tab location', () => {
        const historyPage: NavigationPage = {
            component: 'History',
            url: '/history?weeks=12',
            props: { auth: { user: { id: 7 } } },
        };
        startContextualBackSession(historyPage, router);
        writeTabMemory('history', {
            href: '/history?weeks=12',
            scrollY: 460,
        });
        window.history.replaceState({}, '', '/plan');

        fireBefore('/plan', ['runs']);

        expect(readTabMemory('history')).toEqual({
            href: '/history?weeks=12',
            scrollY: 460,
        });
        expect(readContextualOrigin()).toBeNull();
    });

    it('captures a partial visit that changes the tab route', () => {
        const historyPage: NavigationPage = {
            component: 'History',
            url: '/history?weeks=12',
            props: { auth: { user: { id: 7 } } },
        };
        startContextualBackSession(historyPage, router);
        window.history.replaceState({}, '', '/history?weeks=12');
        window.scrollY = 240;

        fireBefore('/history?weeks=4', ['runs']);

        expect(readTabMemory('history')).toEqual({
            href: '/history?weeks=12',
            scrollY: 240,
        });
    });

    it('clears contextual state when the signed-in identity changes', () => {
        startContextualBackSession(PLAN_PAGE, router);
        writeContextualOrigin({
            href: '/plan',
            scrollY: 300,
            tab: 'plan',
        });
        writeTabMemory('plan', {
            href: '/plan',
            scrollY: 300,
            selectedDay: '2026-06-16',
        });
        fireNavigate({
            component: 'Home',
            url: '/',
            props: { auth: { user: null } },
        });

        expect(readContextualOrigin()).toBeNull();
        expect(readTabMemory('plan')).toBeNull();
    });

    it('restores scroll only when returning to the recorded route, then clears it', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => {
                callback(0);
                return 1;
            },
        );
        startContextualBackSession(
            {
                component: 'Runs/Show',
                url: '/activities/42',
                props: { auth: { user: { id: 7 } } },
            },
            router,
        );
        writeContextualOrigin({
            href: '/plan?day=2026-06-16',
            scrollY: 312,
            tab: 'plan',
        });

        fireNavigate({
            component: 'Plan',
            url: '/plan?day=2026-06-16',
            props: { auth: { user: { id: 7 } } },
        });

        expect(scrollTo).toHaveBeenCalledWith({ top: 312, behavior: 'auto' });
        expect(readContextualOrigin()).toBeNull();
    });

    it('rejects an external saved destination', () => {
        writeContextualOrigin({
            href: 'https://example.com/plan',
            scrollY: 300,
            tab: 'plan',
        });

        expect(readContextualOrigin()).toBeNull();
    });

    it('stores and clears the selected plan day in session memory', () => {
        rememberPlanSelectedDay('2026-06-16');

        expect(readPlanSelectedDay()).toBe('2026-06-16');

        clearNavigationMemory();

        expect(readPlanSelectedDay()).toBeNull();
    });

    it('restores a tab scroll when its saved route is revisited', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => {
                callback(0);
                return 1;
            },
        );
        startContextualBackSession(
            {
                component: 'History',
                url: '/history',
                props: { auth: { user: { id: 7 } } },
            },
            router,
        );
        writeTabMemory('plan', {
            href: '/plan?day=2026-06-16',
            scrollY: 560,
            selectedDay: '2026-06-16',
        });

        fireNavigate({
            component: 'Plan',
            url: '/plan?day=2026-06-16',
            props: { auth: { user: { id: 7 } } },
        });

        expect(scrollTo).toHaveBeenCalledWith({ top: 560, behavior: 'auto' });
        expect(readTabMemory('plan')).toEqual({
            href: '/plan?day=2026-06-16',
            scrollY: 560,
            selectedDay: '2026-06-16',
        });
    });

    it('retries scroll restoration when deferred page content finishes loading', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => {
                callback(0);
                return 1;
            },
        );
        startContextualBackSession(
            {
                component: 'History',
                url: '/history?weeks=12',
                props: { auth: { user: { id: 7 } } },
            },
            router,
        );
        writeTabMemory('plan', {
            href: '/plan?day=2026-06-16',
            scrollY: 560,
            selectedDay: '2026-06-16',
        });

        fireNavigate({
            ...PLAN_PAGE,
            url: '/plan?day=2026-06-16',
        });
        fireFinish(['runs']);

        expect(scrollTo).toHaveBeenCalledTimes(2);
        expect(scrollTo).toHaveBeenLastCalledWith({
            top: 560,
            behavior: 'auto',
        });
    });

    it('notifies tab-memory subscribers on every writer and keeps the snapshot a stable string', () => {
        const listener = vi.fn();
        const unsubscribe = subscribeToTabMemory(listener);

        writeTabMemory('history', { href: '/history', scrollY: 10 });
        rememberPlanSelectedDay('2026-06-16');
        clearTabMemory('history');
        clearNavigationMemory();

        expect(listener).toHaveBeenCalledTimes(4);
        expect(tabMemorySnapshot()).toBeNull();

        unsubscribe();
        writeTabMemory('history', { href: '/history', scrollY: 10 });

        expect(listener).toHaveBeenCalledTimes(4);
        expect(tabMemorySnapshot()).toBe(tabMemorySnapshot());
    });

    it('parses a stored tab-memory snapshot and ignores malformed ones', () => {
        writeTabMemory('plan', {
            href: '/plan',
            scrollY: 0,
            selectedDay: '2026-06-16',
        });

        expect(parseTabMemory(tabMemorySnapshot()).plan).toEqual({
            href: '/plan',
            scrollY: 0,
            selectedDay: '2026-06-16',
        });
        expect(parseTabMemory(null)).toEqual({});
        expect(parseTabMemory('not json')).toEqual({});
    });
});
