import type { PendingVisit } from '@inertiajs/core';
import type { ComponentType } from 'react';

import { router } from '@inertiajs/react';

import type { ContextualOrigin } from '@/lib/nav';
import type { PendingTab } from '@/lib/pendingTab';

import InboxSkeleton from '@/components/inbox/InboxSkeleton';
import ProfileSkeleton from '@/components/profile/ProfileSkeleton';
import RunShowSkeleton from '@/components/run/RunShowSkeleton';
import SettingsSkeleton from '@/components/settings/SettingsSkeleton';
import { readContextualOrigin } from '@/lib/navigationMemory';
import { navTabFor } from '@/lib/navRoutes';
import { pendingTabSnapshot, showPending } from '@/lib/pendingTab';
import {
    prefersReducedMotion,
    screenForPath,
    slideHistoryTraversals,
    startSlide,
    visitDirection,
} from '@/lib/pushSlide';

const SKELETONS: Readonly<Record<string, ComponentType>> = {
    Inbox: InboxSkeleton,
    Profile: ProfileSkeleton,
    'Settings/Index': SettingsSkeleton,
};

function originFrom(from: string): ContextualOrigin | null {
    const tab = navTabFor(from);
    if (tab === null) {
        return readContextualOrigin();
    }

    const { pathname, search, hash } = window.location;

    return { href: pathname + search + hash, scrollY: window.scrollY, tab };
}

/** Whether the tapped History row's run has a saved effort, or null when no row said. */
function effortSavedOf(row: string | undefined): boolean | null {
    return row === undefined ? null : row === 'true';
}

function skeletonFor(
    screen: string,
    effortSaved: boolean | null,
): ComponentType {
    if (screen !== 'Runs/Show') {
        return SKELETONS[screen] ?? (() => null);
    }

    return function PushedRunSkeleton() {
        return <RunShowSkeleton effortSaved={effortSaved} />;
    };
}

/**
 * Turns on the phone's push slide: a push into a pushed screen or a pop to its
 * back target slides on Inertia's `start`, carrying the destination's skeleton
 * (lib/pendingTab draws it), and the swap itself skips the cross-fade.
 * `currentComponent` reads the page on screen. Returns the uninstall.
 */
export function startPushStack(currentComponent: () => string): () => void {
    let sliding: {
        visit: PendingVisit;
        transition: ViewTransition | null;
    } | null = null;
    let tappedRow: string | undefined;

    const onClick = (event: MouseEvent) => {
        tappedRow =
            event.target instanceof Element
                ? event.target.closest<HTMLElement>('[data-effort-saved]')
                      ?.dataset.effortSaved
                : undefined;
    };

    const offBefore = router.on('before', (event) => {
        const { visit } = event.detail;
        if (
            visit.showProgress &&
            visitDirection(currentComponent(), visit.url) !== null
        ) {
            visit.viewTransition = false;
        }
    });

    const offStart = router.on('start', (event) => {
        const { visit } = event.detail;
        const row = tappedRow;
        tappedRow = undefined;
        if (visit.async || visit.prefetch || visit.method !== 'get') {
            return;
        }

        const from = currentComponent();
        const direction = visitDirection(from, visit.url);
        const screen = screenForPath(visit.url.pathname);
        if (direction === null || screen === null) {
            return;
        }

        const tab = navTabFor(screen);
        const place = {
            from,
            href: visit.url.pathname + visit.url.search,
            scrollY: window.scrollY,
            visit,
        };
        const next: PendingTab =
            tab === null
                ? {
                      tab,
                      pushed: {
                          screen,
                          origin: originFrom(from),
                          Skeleton: skeletonFor(screen, effortSavedOf(row)),
                      },
                      ...place,
                  }
                : { tab, ...place };

        if (pendingTabSnapshot()?.visit === visit) {
            showPending(null);
        }

        if (prefersReducedMotion()) {
            showPending(next);
            return;
        }

        const slide = { visit, transition: null as ViewTransition | null };
        sliding = slide;
        slide.transition = startSlide(direction, () => {
            if (sliding === slide) {
                showPending(next);
            }
        });
    });

    const offFinish = router.on('finish', (event) => {
        const { visit } = event.detail;
        if (sliding === null || sliding.visit !== visit) {
            return;
        }

        if (
            !visit.completed ||
            window.location.pathname !== visit.url.pathname
        ) {
            sliding.transition?.skipTransition();
        }
        sliding = null;
    });

    const stopTraversals = slideHistoryTraversals(currentComponent);
    document.addEventListener('click', onClick, true);

    return () => {
        offBefore();
        offStart();
        offFinish();
        stopTraversals();
        document.removeEventListener('click', onClick, true);
    };
}
