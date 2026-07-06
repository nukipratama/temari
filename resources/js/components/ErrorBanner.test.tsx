import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ErrorBanner from './ErrorBanner';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, flash: {}, demoLoginEnabled: false } as const;

describe('ErrorBanner', () => {
    it('renders nothing when there are no errors', () => {
        setMockPage({ ...base, errors: {} });
        const { container } = render(<ErrorBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('surfaces the first error message with an alert role', () => {
        setMockPage({ ...base, errors: { strava: 'Gagal nyambungin Strava. Coba lagi sebentar ya.' } });
        render(<ErrorBanner />);
        expect(screen.getByRole('alert')).toHaveTextContent('Gagal nyambungin Strava');
    });

    it('dismisses when the close button is clicked', () => {
        setMockPage({ ...base, errors: { demo: 'Demo user belum di-seed.' } });
        render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('re-shows the banner when a fresh error message appears after dismissal', () => {
        setMockPage({ ...base, errors: { strava: 'Gagal nyambungin Strava. Coba lagi sebentar ya.' } });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        setMockPage({ ...base, errors: { demo: 'Demo user belum di-seed.' } });
        rerender(<ErrorBanner />);
        expect(screen.getByRole('alert')).toHaveTextContent('Demo user belum di-seed.');
    });
});
