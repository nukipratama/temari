import { router } from '@inertiajs/react';

import type { ContextualOrigin, TabId } from '@/lib/nav';

import { ITEMS, navTabFor } from '@/lib/nav';

const ORIGIN_KEY = 'temari:navigation:origin';

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
    return ITEMS.find((item) => item.id === value)?.id ?? null;
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
        return;
    }

    window.sessionStorage.setItem(
        ORIGIN_KEY,
        JSON.stringify({ ...origin, href }),
    );
}

function restoreScroll(scrollY: number): void {
    window.requestAnimationFrame(() => {
        window.scrollTo({ top: scrollY, behavior: 'auto' });
    });
}

export function startContextualBackSession(initialPage: NavigationPage): void {
    window.sessionStorage.removeItem(ORIGIN_KEY);

    let currentPage = initialPage;
    let currentIdentity = identityFor(initialPage);
    let pendingOrigin: PendingOrigin | null = null;

    router.on('before', (event) => {
        const tab = navTabFor(currentPage.component);
        const target = internalHref(event.detail.visit.url);
        const href = internalHref(currentPage.url);

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

        if (nextIdentity !== currentIdentity) {
            window.sessionStorage.removeItem(ORIGIN_KEY);
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
                restoreScroll(origin.scrollY);
            }
            window.sessionStorage.removeItem(ORIGIN_KEY);
        } else if (page.component !== 'Runs/Show') {
            window.sessionStorage.removeItem(ORIGIN_KEY);
        }

        pendingOrigin = null;
        currentPage = page;
        currentIdentity = nextIdentity;
    });
}
