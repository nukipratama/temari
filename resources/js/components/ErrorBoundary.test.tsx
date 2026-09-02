import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { reportClientError } from '@/lib/clientErrorReporter';

import ErrorBoundary from './ErrorBoundary';

vi.mock('@/lib/clientErrorReporter', () => ({
    reportClientError: vi.fn(),
    installGlobalErrorReporting: vi.fn(),
}));

function Boom(): never {
    throw new Error('kaboom');
}

describe('ErrorBoundary', () => {
    it('renders children when there is no error', () => {
        render(
            <ErrorBoundary>
                <p>healthy page</p>
            </ErrorBoundary>,
        );

        expect(screen.getByText('healthy page')).toBeInTheDocument();
    });

    it('renders the fallback and reports the error when a child throws', () => {
        // React routes the caught error to console.error; silence it for a clean run.
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});

        render(
            <ErrorBoundary>
                <Boom />
            </ErrorBoundary>,
        );

        expect(screen.getByText('Oops, something broke.')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /reload/i }),
        ).toBeInTheDocument();
        expect(reportClientError).toHaveBeenCalledWith(
            expect.objectContaining({ message: 'kaboom' }),
        );

        consoleError.mockRestore();
    });

    it('reloads the page when the fallback button is clicked', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const reload = vi.fn();
        vi.stubGlobal('location', { ...window.location, reload });

        render(
            <ErrorBoundary>
                <Boom />
            </ErrorBoundary>,
        );

        await userEvent.click(screen.getByRole('button', { name: /reload/i }));

        expect(reload).toHaveBeenCalledOnce();

        vi.unstubAllGlobals();
        consoleError.mockRestore();
    });
});
