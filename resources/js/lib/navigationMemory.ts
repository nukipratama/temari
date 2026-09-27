import type { router as inertiaRouter } from '@inertiajs/react';

import type { ContextualOrigin, TabId } from '@/lib/nav';

import { isTabId, navTabFor } from '@/lib/navRoutes';

const ORIGIN_KEY = 'temari:navigation:origin';
const ORIGIN_CHANGE_EVENT = 'temari:navigation:origin-change';

export interface NavigationPage {
    component: string;
    url: string;
    props: object;
}

interface PendingOrigin {
    target: string;
    origin: ContextualOrigin;
}

function internalHref(value: string | URL): string | null {
    try {
        const url = new URL(
            typeof value === 'string' ? value : value.href,
            window.location.origin,
        );

        return url.origin === window.location.origin
            ? `${url.pathname}${url.search}${url.hash}`
            : null;
    } catch {
        return null;
    }
}

function identityFor(page: NavigationPage): string | null {
    const props = page.props as {
        auth?: {
            user?: { id?: number | string | null } | null;
        };
    };
    const id = props.auth?.user?.id;

    return id == null ? null : String(id);
}

function tabId(value: unknown): TabId | null {
    return isTabId(value) ? value : null;
}

export function subscribeToContextualOrigin(listener: () => void): () => void {
    window.addEventListener(ORIGIN_CHANGE_EVENT, listener);
    return () => window.removeEventListener(ORIGIN_CHANGE_EVENT, listener);
}

export function contextualOriginSnapshot(): string | null {
    return typeof window === 'undefined'
        ? null
        : window.sessionStorage.getItem(ORIGIN_KEY);
}

function notifyContextualOriginChange(): void {
    window.dispatchEvent(new Event(ORIGIN_CHANGE_EVENT));
}

export function readContextualOrigin(): ContextualOrigin | null {
    const stored = window.sessionStorage.getItem(ORIGIN_KEY);
    if (stored === null) return null;

    try {
        const value: unknown = JSON.parse(stored);
        if (typeof value !== 'object' || value === null) return null;

        const origin = value as {
            href?: unknown;
            scrollY?: unknown;
            tab?: unknown;
        };
        const href =
            typeof origin.href === 'string' ? internalHref(origin.href) : null;
        const tab = tabId(origin.tab);

        return href !== null &&
            typeof origin.scrollY === 'number' &&
            Number.isFinite(origin.scrollY) &&
            origin.scrollY >= 0 &&
            tab !== null
            ? { href, scrollY: origin.scrollY, tab }
            : null;
    } catch {
        return null;
    }
}

export function writeContextualOrigin(origin: ContextualOrigin | null): void {
    if (origin === null) {
        window.sessionStorage.removeItem(ORIGIN_KEY);
        notifyContextualOriginChange();
        return;
    }

    const href = internalHref(origin.href);
    if (
        href === null ||
        tabId(origin.tab) === null ||
        !Number.isFinite(origin.scrollY) ||
        origin.scrollY < 0
    ) {
        window.sessionStorage.removeItem(ORIGIN_KEY);
        notifyContextualOriginChange();
        return;
    }

    window.sessionStorage.setItem(
        ORIGIN_KEY,
        JSON.stringify({ ...origin, href }),
    );
    notifyContextualOriginChange();
}

export function startContextualBackSession(
    initialPage: NavigationPage,
    router: typeof inertiaRouter,
): void {
    window.sessionStorage.removeItem(ORIGIN_KEY);
    notifyContextualOriginChange();

    let currentPage = initialPage;
    let currentIdentity = identityFor(initialPage);
    let pendingOrigin: PendingOrigin | null = null;
    let pendingScrollRestore: { href: string; scrollY: number } | null = null;

    function restoreScroll(href: string, scrollY: number): void {
        const pending = { href, scrollY };
        pendingScrollRestore = pending;

        window.requestAnimationFrame(() => {
            if (
                pendingScrollRestore !== pending ||
                internalHref(currentPage.url) !== href
            ) {
                return;
            }

            window.scrollTo({ top: scrollY, behavior: 'auto' });
            const maxScroll = Math.max(
                0,
                Math.max(
                    document.documentElement.scrollHeight,
                    document.body.scrollHeight,
                ) - window.innerHeight,
            );
            if (scrollY <= maxScroll) pendingScrollRestore = null;
        });
    }

    router.on('before', (event) => {
        const target = internalHref(event.detail.visit.url);
        const href = internalHref(currentPage.url);
        const location = internalHref(window.location.href);
        if (
            event.detail.visit.only?.length &&
            (target === href || target === location)
        ) {
            return;
        }

        pendingScrollRestore = null;
        const tab = navTabFor(currentPage.component);
        pendingOrigin =
            tab !== null && target !== null && href !== null
                ? {
                      target,
                      origin: { href, scrollY: window.scrollY, tab },
                  }
                : null;
    });

    router.on('navigate', (event) => {
        const page = event.detail.page as NavigationPage;
        const nextIdentity = identityFor(page);
        const pageHref = internalHref(page.url);
        pendingScrollRestore = null;
        const identityChanged = nextIdentity !== currentIdentity;
        currentPage = page;
        currentIdentity = nextIdentity;

        if (identityChanged) {
            writeContextualOrigin(null);
        } else if (
            page.component === 'Runs/Show' &&
            pendingOrigin !== null &&
            pendingOrigin.target === pageHref
        ) {
            writeContextualOrigin(pendingOrigin.origin);
        } else if (navTabFor(page.component) !== null) {
            const tab = navTabFor(page.component);
            const origin = readContextualOrigin();
            if (
                tab !== null &&
                origin !== null &&
                origin.href === pageHref &&
                origin.tab === tab
            ) {
                restoreScroll(pageHref, origin.scrollY);
            }
            writeContextualOrigin(null);
        } else if (page.component !== 'Runs/Show') {
            writeContextualOrigin(null);
        }

        pendingOrigin = null;
    });

    router.on('finish', (event) => {
        if (!event.detail.visit.only?.length || pendingScrollRestore === null) {
            return;
        }

        restoreScroll(
            pendingScrollRestore.href,
            pendingScrollRestore.scrollY,
        );
    });
}
