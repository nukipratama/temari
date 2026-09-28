import '@testing-library/jest-dom/vitest';
import { router } from '@inertiajs/react';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

import { installOverlayHistory } from '@/hooks/useOverlayHistory';

// jsdom-only globals, loaded after setup.ts (module mocks) for the `dom`
// Vitest project only — see vitest.config.ts.

// jsdom ships no matchMedia. Anything asking the environment about itself
// (display-mode for the installed-app checks, pointer coarseness, reduced
// motion) needs it to exist, so default every query to "no match" — a plain
// desktop browser tab. Reduced motion is the exception: under it the count-up
// tween snaps to its target instead of running over ~900ms of real time, which
// keeps time-based assertions deterministic and stops a frame firing after a
// test file's jsdom env is torn down. Tests that care override it per-file.
if (typeof window !== 'undefined' && !window.matchMedia) {
    window.matchMedia = ((query: string) => ({
        matches: query.includes('prefers-reduced-motion'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia;
}

afterEach(() => {
    cleanup();
});

// jsdom stubs for browser APIs not implemented in the test environment.
// observe() replays the real API's first delivery, which components rely on
// for their initial measurement.
globalThis.ResizeObserver = class ResizeObserver {
    constructor(private readonly callback: ResizeObserverCallback) {}

    observe = vi.fn(() => {
        this.callback([], this);
    });

    unobserve = vi.fn();
    disconnect = vi.fn();
};

// jsdom has no viewport, so every observed element counts as on screen.
globalThis.IntersectionObserver = class IntersectionObserver {
    constructor(private readonly callback: IntersectionObserverCallback) {}

    root = null;
    rootMargin = '';
    scrollMargin = '';
    thresholds = [];

    observe = vi.fn((target: Element) => {
        this.callback(
            [{ target, isIntersecting: true } as IntersectionObserverEntry],
            this,
        );
    });

    unobserve = vi.fn();
    disconnect = vi.fn();
    takeRecords = vi.fn(() => []);
};

// jsdom has no layout engine, so scrollIntoView isn't implemented at all
// (not even as a no-op) — calling it throws "is not a function".
Element.prototype.scrollIntoView = vi.fn();

// Ahead of any listener a test adds, as app.tsx installs it ahead of Inertia's.
installOverlayHistory(router);
