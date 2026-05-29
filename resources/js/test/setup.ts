import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';
import { MotionGlobalConfig } from 'framer-motion';
import { createElement, type ReactNode } from 'react';

// Make framer-motion animations resolve instantly in tests. Without this its
// animation frameloop runs over real time and a frame can fire after a test
// file's jsdom env is torn down, throwing an unhandled "window is not defined"
// that fails CI even when every test passes. Instant animations also make
// AnimatePresence exits deterministic (children removed synchronously).
MotionGlobalConfig.skipAnimations = true;

const DEFAULT_PAGE_PROPS: Record<string, unknown> = {
    auth: { user: null },
    flash: { success: null, error: null, info: null },
    demoLoginEnabled: false,
    onboarding: { forceShow: false },
};
const DEFAULT_URL = '/';

// Global Inertia mock — real Link/Head/usePage need an app context that
// unit tests don't bootstrap. Tests override usePage props via
// `setMockPage()`; state resets between tests so files don't bleed.
let mockPageProps: Record<string, unknown> = { ...DEFAULT_PAGE_PROPS };
let mockUrl = DEFAULT_URL;

export function setMockPage(props: Record<string, unknown>, url = DEFAULT_URL) {
    mockPageProps = { ...DEFAULT_PAGE_PROPS, ...props };
    mockUrl = url;
}

afterEach(() => {
    cleanup();
    mockPageProps = { ...DEFAULT_PAGE_PROPS };
    mockUrl = DEFAULT_URL;
});

vi.mock('@inertiajs/react', async () => {
    // Inertia-specific props that React would warn about if they leaked onto a
    // raw <a>. Keep this aligned with @inertiajs/react Link's public surface.
    const INERTIA_LINK_PROPS = new Set([
        'preserveScroll',
        'preserveState',
        'replace',
        'only',
        'except',
        'data',
        'method',
        'as',
        'headers',
        'errorBag',
        'queryStringArrayFormat',
        'async',
        'prefetch',
        'cacheFor',
        'onStart',
        'onProgress',
        'onFinish',
        'onCancel',
        'onSuccess',
        'onError',
        'onCancelToken',
        'onBefore',
    ]);

    const linkComponent = ({
        href,
        children,
        className,
        dangerouslySetInnerHTML,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        className?: string;
        dangerouslySetInnerHTML?: { __html: string };
        [k: string]: unknown;
    }) => {
        const domProps: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(rest)) {
            if (!INERTIA_LINK_PROPS.has(k)) domProps[k] = v;
        }
        return createElement(
            'a',
            { href, className, dangerouslySetInnerHTML, ...domProps },
            dangerouslySetInnerHTML ? undefined : children,
        );
    };

    return {
        Head: ({ children }: { children?: ReactNode }) => children ?? null,
        Link: linkComponent,
        usePage: () => ({ props: mockPageProps, url: mockUrl }),
        useForm: () => ({
            data: {},
            errors: {},
            processing: false,
            post: vi.fn(),
            get: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
            reset: vi.fn(),
        }),
        router: { post: vi.fn(), get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    };
});

// jsdom stubs for browser APIs not implemented in the test environment.
globalThis.ResizeObserver = class ResizeObserver {
    observe = vi.fn();
    unobserve = vi.fn();
    disconnect = vi.fn();
};

// react-chartjs-2 needs canvas — stub Chart components.
vi.mock('react-chartjs-2', () => ({
    Line: () => createElement('div', { 'data-testid': 'line-chart' }),
    Bar: () => createElement('div', { 'data-testid': 'bar-chart' }),
}));
