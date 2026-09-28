import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// .test.ts files that reach for window/document (localStorage, matchMedia,
// renderHook against real DOM APIs, etc.) despite the .ts extension — found
// by running the node project and moving over whatever failed. Everything
// else under .test.ts runs in `node`, which is much cheaper to spin up than
// jsdom; .test.tsx always renders components, so it always needs jsdom.
const TS_FILES_NEEDING_DOM = [
    'resources/js/hooks/useAnalysisTrigger.test.ts',
    'resources/js/hooks/useCoarsePointer.test.ts',
    'resources/js/hooks/useCooldownCountdown.test.ts',
    'resources/js/hooks/useCountUp.test.ts',
    'resources/js/hooks/useDemoGuard.test.ts',
    'resources/js/hooks/useDismissable.test.ts',
    'resources/js/hooks/useExitTransition.test.ts',
    'resources/js/hooks/useFocusReturn.test.ts',
    'resources/js/hooks/useHorizontalSwipe.test.ts',
    'resources/js/hooks/useIsDarkGround.test.ts',
    'resources/js/hooks/useOverlayHistory.test.ts',
    'resources/js/hooks/usePendingPost.test.ts',
    'resources/js/hooks/usePopover.test.ts',
    'resources/js/hooks/useReducedMotion.test.ts',
    'resources/js/hooks/useRunQuestions.test.ts',
    'resources/js/hooks/useScrollFade.test.ts',
    'resources/js/hooks/useSystemTheme.test.ts',
    'resources/js/hooks/useTheme.test.ts',
    'resources/js/hooks/useViewTransitions.test.ts',
    'resources/js/lib/anchors.test.ts',
    'resources/js/lib/appBadge.test.ts',
    'resources/js/lib/card/print.test.ts',
    'resources/js/lib/card/styles/broadsheet.test.ts',
    'resources/js/lib/card/styles/ticket.test.ts',
    'resources/js/lib/card/styles/topo.test.ts',
    'resources/js/lib/card/svg.test.ts',
    'resources/js/lib/clientErrorReporter.test.ts',
    'resources/js/lib/designTokens.test.ts',
    'resources/js/lib/http.test.ts',
    'resources/js/lib/lazyIsland.test.ts',
    'resources/js/lib/navigationMemory.test.ts',
    'resources/js/lib/registerServiceWorker.test.ts',
    'resources/js/lib/webPush.test.ts',
    'resources/js/pages/Activities/useCalendar.test.ts',
    'resources/js/pages/Runs/useRunShow.test.ts',
    'resources/js/pages/Settings/useNotificationPrefs.test.ts',
    'resources/js/test/overlayHistory.test.ts',
];

const alias = {
    '@': path.resolve(__dirname, 'resources/js'),
    // Test-only: the brand generators are the source of truth for the
    // derived token set, and are pinned from Vitest. They are never
    // aliased in vite.config.ts, so none of this reaches a bundle.
    '@brand': path.resolve(__dirname, 'resources/brand'),
    // Test-only, same reasoning: source-guard scripts export their
    // rule tables for direct testing. Never aliased in vite.config.ts.
    '@scripts': path.resolve(__dirname, 'scripts'),
};

export default defineConfig({
    plugins: [react()],
    resolve: { alias },
    test: {
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
        projects: [
            {
                extends: true,
                test: {
                    name: 'node',
                    globals: true,
                    environment: 'node',
                    setupFiles: ['./resources/js/test/setup.ts'],
                    include: ['resources/js/**/*.test.ts'],
                    exclude: TS_FILES_NEEDING_DOM,
                },
            },
            {
                extends: true,
                test: {
                    name: 'dom',
                    globals: true,
                    environment: 'jsdom',
                    setupFiles: [
                        './resources/js/test/setup.ts',
                        './resources/js/test/setup.dom.ts',
                    ],
                    include: [
                        'resources/js/**/*.test.tsx',
                        ...TS_FILES_NEEDING_DOM,
                    ],
                },
            },
        ],
    },
});
