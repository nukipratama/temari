import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';

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
    mockPageProps = props;
    mockUrl = url;
}

afterEach(() => {
    cleanup();
    mockPageProps = { ...DEFAULT_PAGE_PROPS };
    mockUrl = DEFAULT_URL;
});

vi.mock('@inertiajs/react', async () => {
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
    }) =>
        createElement(
            'a',
            { href, className, dangerouslySetInnerHTML, ...rest },
            dangerouslySetInnerHTML ? undefined : children,
        );

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
        router: { post: vi.fn(), get: vi.fn() },
    };
});

// react-chartjs-2 needs canvas — stub Chart components.
vi.mock('react-chartjs-2', () => ({
    Line: () => createElement('div', { 'data-testid': 'line-chart' }),
    Bar: () => createElement('div', { 'data-testid': 'bar-chart' }),
}));
