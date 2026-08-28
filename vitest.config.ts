import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            // Test-only: the brand generators are the source of truth for the
            // mascot and its catalogue, and are pinned from Vitest. They are
            // never aliased in vite.config.ts, so none of this reaches a bundle.
            '@brand': path.resolve(__dirname, 'resources/brand'),
            // Test-only, same reasoning: source-guard scripts export their
            // rule tables for direct testing. Never aliased in vite.config.ts.
            '@scripts': path.resolve(__dirname, 'scripts'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html', 'json-summary'],
            include: ['resources/js/**/*.{ts,tsx}'],
            exclude: [
                'resources/js/**/*.test.{ts,tsx}',
                'resources/js/test/**',
                'resources/js/types/**',
                'resources/js/app.tsx',
            ],
            thresholds: {
                lines: 95,
                functions: 95,
            },
        },
    },
});
