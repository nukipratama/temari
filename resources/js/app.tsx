import type { ComponentType } from 'react';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import ErrorBoundary from '@/components/ErrorBoundary';
import { installOverlayHistory } from '@/hooks/useOverlayHistory';
import {
    syncAppBadge,
    syncAppBadgeOnVisible,
    unreadCountFromProps,
} from '@/lib/appBadge';
import { recoverFromStaleChunks } from '@/lib/chunkRecovery';
import { installGlobalErrorReporting } from '@/lib/clientErrorReporter';
import { captureInstallPrompt } from '@/lib/installPrompt';
import { startContextualBackSession } from '@/lib/navigationMemory';
import { registerServiceWorker } from '@/lib/registerServiceWorker';
import { reportSeen } from '@/lib/webPush';

const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'Temari';

// Every visitor, not just push subscribers: the worker also serves the offline
// fallback page. See lib/registerServiceWorker.ts.
registerServiceWorker();
syncAppBadgeOnVisible();

// The app-icon badge follows the inbox: every visit ships the unread count, so
// reading the inbox clears the badge on its next partial reload.
router.on('navigate', (event) => {
    syncAppBadge(unreadCountFromProps(event.detail.page.props));
    void reportSeen(event.detail.page.props);
});

installGlobalErrorReporting();
captureInstallPrompt();

let inFlightVisit: { url: string; method: string } | null = null;
router.on('start', (event) => {
    const { visit } = event.detail;
    if (visit.async || visit.prefetch) {
        return;
    }
    inFlightVisit = { url: visit.url.href, method: visit.method };
});
router.on('finish', (event) => {
    const { visit } = event.detail;
    if (visit.async || visit.prefetch) {
        return;
    }
    inFlightVisit = null;
});
recoverFromStaleChunks(() => {
    if (inFlightVisit === null) {
        return false;
    }
    if (inFlightVisit.method === 'get') {
        window.location.assign(inFlightVisit.url);
    } else {
        window.location.reload();
    }
    return true;
});
installOverlayHistory(router);

const pages = import.meta.glob<{ default: ComponentType }>([
    './pages/**/*.tsx',
    '!./pages/**/*.test.tsx',
]);

/** The four bottom-nav destinations, by Inertia page name. */
const TAB_PAGES = ['Home', 'Plan', 'Trends', 'History'];

/**
 * Fetches the JS chunk for each tab once the browser is idle, so the first tap
 * on a tab does not wait on a network round trip for its code.
 *
 * Not `{ eager: true }` on the glob: that would pull the lazy `charts` and
 * `maps` vendor chunks into the entry graph.
 */
function warmTabChunks(): void {
    if (typeof window === 'undefined') return;
    // `connection` is still not in lib.dom; respecting Data Saver matters more
    // than avoiding the cast.
    const connection = (
        navigator as Navigator & { connection?: { saveData?: boolean } }
    ).connection;
    if (connection?.saveData) return;

    const warm = () => {
        for (const name of TAB_PAGES) {
            void pages[`./pages/${name}.tsx`]?.().catch(() => {
                // A cold chunk failing to prefetch is not an error worth
                // surfacing: the real navigation will retry and report.
            });
        }
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(warm, { timeout: 5000 });
    } else {
        window.setTimeout(warm, 2000);
    }
}

void createInertiaApp({
    title: (title) => (title ? `${title} · ${APP_NAME}` : APP_NAME),
    resolve: async (name) => {
        const importer = pages[`./pages/${name}.tsx`];
        if (!importer) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        const module: { default: ComponentType } | undefined = await importer();
        // A stale chunk resolves to nothing once recoverFromStaleChunks has taken over; the page is reloading.
        if (module === undefined) {
            return new Promise<never>(() => undefined);
        }
        return module.default;
    },
    setup({ el, App, props }) {
        startContextualBackSession(props.initialPage, router);
        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
        syncAppBadge(unreadCountFromProps(props.initialPage.props));
        void reportSeen(props.initialPage.props);
        warmTabChunks();
    },
    // No progress bar at all. Deferred props paint a shell immediately and the
    // route cross-fade covers the swap, so a bar would only ever flash.
    progress: false,
});
