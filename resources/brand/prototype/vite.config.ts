import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, './src'),
        },
    },
    server: {
        // Reuses the sail container's already-published VITE_PORT mapping
        // (7002 -> host 7002) rather than adding a new compose.yaml port —
        // this prototype and the real app's own `npm run dev` can't run
        // at the same time, which is fine for a throwaway review app.
        host: true,
        port: 7002,
        strictPort: true,
    },
});
