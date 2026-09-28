import type { GlobalEvent } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { NavigationPage } from './navigationMemory';

import {
    readContextualOrigin,
    startContextualBackSession,
    writeContextualOrigin,
} from './navigationMemory';

function eventHandler(name: 'before' | 'navigate' | 'finish') {
    const call = vi
        .mocked(router.on)
        .mock.calls.find(([event]) => event === name);
    if (!call) throw new Error(`router.on was never called for "${name}"`);

    return call[1];
}

function fireBefore(url: string) {
    eventHandler('before')({
        detail: { visit: { url: new URL(url, window.location.origin) } },
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
        startContextualBackSession(PLAN_PAGE, router);

        expect(readContextualOrigin()).toBeNull();
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

    it('clears contextual state when the signed-in identity changes', () => {
        startContextualBackSession(PLAN_PAGE, router);
        writeContextualOrigin({
            href: '/plan',
            scrollY: 300,
            tab: 'plan',
        });
        fireNavigate({
            component: 'Home',
            url: '/',
            props: { auth: { user: null } },
        });

        expect(readContextualOrigin()).toBeNull();
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

    it('retries contextual scroll restoration after deferred page content loads', () => {
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
            href: '/history?weeks=12',
            scrollY: 460,
            tab: 'history',
        });

        fireNavigate({
            component: 'History',
            url: '/history?weeks=12',
            props: { auth: { user: { id: 7 } } },
        });
        fireFinish(['runs']);

        expect(scrollTo).toHaveBeenCalledTimes(2);
        expect(scrollTo).toHaveBeenLastCalledWith({
            top: 460,
            behavior: 'auto',
        });
    });

    it('rejects an external saved destination', () => {
        writeContextualOrigin({
            href: 'https://example.com/plan',
            scrollY: 300,
            tab: 'plan',
        });

        expect(readContextualOrigin()).toBeNull();
    });
});
