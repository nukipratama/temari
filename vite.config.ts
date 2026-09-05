import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

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
    ],
    build: {
        rollupOptions: {
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
                            name: 'motion',
                            test: (id) =>
                                id.includes('node_modules/framer-motion'),
                            priority: 10,
                        },
                        {
                            name: 'base-ui',
                            test: (id) =>
                                id.includes('node_modules/@base-ui') ||
                                id.includes('node_modules/@floating-ui'),
                            priority: 10,
                        },
                    ],
                },
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
