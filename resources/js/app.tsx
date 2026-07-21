import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { addCollection } from '@iconify/react';
import ErrorBoundary from '@/components/ErrorBoundary';
import { installGlobalErrorReporting } from '@/lib/clientErrorReporter';
import { registerServiceWorker } from '@/lib/registerServiceWorker';
import { mdiBundle } from '@/lib/iconBundle';

const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'Temari';

// Render mdi icons from the bundled collection so <Icon> never fetches from
// api.iconify.design (offline + no external connect-src). See lib/iconBundle.ts.
addCollection(mdiBundle);

// Every visitor, not just push subscribers: the worker also serves the offline
// fallback page. See lib/registerServiceWorker.ts.
registerServiceWorker();

installGlobalErrorReporting();

const pages = import.meta.glob<{ default: ComponentType }>([
    './pages/**/*.tsx',
    '!./pages/**/*.test.tsx',
]);

/** The four bottom-nav destinations, by Inertia page name. */
const TAB_PAGES = ['HariIni', 'Koleksi/Kartu', 'Riwayat/Jejak', 'Aku'];

/**
 * Fetches the JS chunk for each tab once the browser is idle, so the first tap
 * on a tab does not wait on a network round trip for its code.
 *
 * Deliberately not `{ eager: true }` on the glob: that folds every page into
 * the entry graph and promotes the `charts` (178KB) and `maps` (156KB) manual
 * chunks from lazy to always-fetched, which would hurt exactly the first load
 * this is meant to protect. Warming four importers by hand keeps those lazy.
 *
 * This is not the route prefetching reverted in #97 either — it fetches static
 * hashed assets that are already cached immutably, and never runs a controller.
 */
function warmTabChunks(): void {
    if (typeof window === 'undefined') return;
    // `connection` is still not in lib.dom; respecting Data Saver matters more
    // than avoiding the cast.
    const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection;
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
        const module = await importer();
        return module.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
        warmTabChunks();
    },
    progress: {
        color: '#0E7A4C',
        showSpinner: false,
    },
});
