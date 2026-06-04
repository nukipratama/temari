import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ErrorBoundary from './ErrorBoundary';
import { reportClientError } from '@/lib/clientErrorReporter';

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
                <p>halaman sehat</p>
            </ErrorBoundary>,
        );

        expect(screen.getByText('halaman sehat')).toBeInTheDocument();
    });

    it('renders the fallback and reports the error when a child throws', () => {
        // React routes the caught error to console.error; silence it for a clean run.
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

        render(
            <ErrorBoundary>
                <Boom />
            </ErrorBoundary>,
        );

        expect(screen.getByText('Waduh, ada yang error.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /muat ulang/i })).toBeInTheDocument();
        expect(reportClientError).toHaveBeenCalledWith(expect.objectContaining({ message: 'kaboom' }));

        consoleError.mockRestore();
    });
});
