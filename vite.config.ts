import { defineConfig, type Plugin } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

const CATALOGUE = '?catalogue';

/** Singletons the app boots once, which the catalogue must share rather than copy. */
const SHARED_WITH_THE_APP = [
    /\/node_modules\/(?:react|react-dom|scheduler|@inertiajs\/[a-z]+)\//,
    /\/resources\/js\/hooks\/useOverlayHistory\.ts$/,
];

/**
 * Gives the design catalogue its own copy of every module it reaches, so the
 * devtools-only examples never change how the production pages are chunked.
 */
function isolateDesignCatalogue(): Plugin {
    return {
        name: 'isolate-design-catalogue',
        apply: 'build',
        enforce: 'pre',
        async resolveId(source, importer, options) {
            if (importer === undefined) {
                return null;
            }
            const inside = importer.endsWith(CATALOGUE);
            const resolved = await this.resolve(
                source,
                inside ? importer.slice(0, -CATALOGUE.length) : importer,
                { ...options, skipSelf: true },
            );
            if (resolved === null || resolved.external === true) {
                return resolved;
            }
            const enters =
                inside ||
                resolved.id.includes('/resources/js/components/catalogue/');
            if (
                !enters ||
                resolved.id.startsWith('\0') ||
                !/\.[cm]?[jt]sx?$/.test(resolved.id) ||
                SHARED_WITH_THE_APP.some((shared) => shared.test(resolved.id))
            ) {
                return resolved;
            }

            return { ...resolved, id: resolved.id + CATALOGUE };
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/fonts.css',
                'resources/css/app.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
        isolateDesignCatalogue(),
    ],
    build: {
        rolldownOptions: {
            output: {
                // Split heavy vendors into their own chunks so a page that doesn't use
                // charts/maps/animation doesn't pull the whole bundle. Without this
                // they all land in one large vendor chunk.
                //
                // `codeSplitting` groups, not `manualChunks`: Rolldown collapses a
                // manualChunks function into one group at priority 0, so its branches
                // cannot outrank each other, and `includeDependenciesRecursively`
                // (default true) then sweeps React into whichever vendor group reaches
                // it first. Explicit groups give react-vendor a priority that wins.
                codeSplitting: {
                    groups: [
                        {
                            name: 'react-vendor',
                            test: (id) =>
                                id.includes('node_modules/react/') ||
                                id.includes('node_modules/react-dom/') ||
                                id.includes('node_modules/scheduler/'),
                            priority: 100,
                        },
                        {
                            // lucide's shared runtime only — never the icon
                            // modules, which must stay loose so Rolldown can
                            // attribute each one to the routes that render it.
                            name: 'lucide-runtime',
                            test: (id) =>
                                !id.endsWith(CATALOGUE) &&
                                id.includes('node_modules/lucide-react/') &&
                                !id.includes('/icons/'),
                            priority: 10,
                        },
                        {
                            name: 'charts',
                            test: (id) =>
                                id.includes('node_modules/chart.js') ||
                                id.includes('node_modules/react-chartjs-2'),
                            priority: 10,
                        },
                        {
                            name: 'maps',
                            test: (id) =>
                                id.includes('node_modules/leaflet') ||
                                id.includes('node_modules/react-leaflet'),
                            priority: 10,
                        },
                        {
                            name: 'base-ui',
                            test: (id) =>
                                !id.endsWith(CATALOGUE) &&
                                (id.includes('node_modules/@base-ui') ||
                                    id.includes('node_modules/@floating-ui')),
                            priority: 10,
                            // Without this the group merges every Base UI part
                            // into one chunk, so a part reached only from a
                            // `lazy()` boundary is still loaded on first paint.
                            entriesAware: true,
                        },
                    ],
                },
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        ws: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
