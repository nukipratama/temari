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
