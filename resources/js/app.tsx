import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'TemanLari';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${APP_NAME}` : APP_NAME),
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');
        const importer = pages[`./pages/${name}.tsx`];
        if (!importer) {
            throw new Error(`Inertia page not found: ${name}`);
        }
        const module = await importer();
        return module.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#2E7D5C',
        showSpinner: false,
    },
});
