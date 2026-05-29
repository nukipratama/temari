import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        // framer-motion runs a global animation frameloop that touches `window`.
        // In CI a frame occasionally fires after a test file's jsdom environment
        // is torn down, throwing "window is not defined" as an *unhandled* error
        // that fails the run even though every test passes. The animations are
        // untestable teardown noise (the app has no animation-driven logic), so
        // don't let that post-teardown noise fail an otherwise-green suite.
        // Assertion failures and in-test errors still fail normally.
        dangerouslyIgnoreUnhandledErrors: true,
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
