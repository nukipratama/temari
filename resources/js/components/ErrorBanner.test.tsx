import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import ErrorBanner from './ErrorBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('ErrorBanner', () => {
    it('renders nothing when there are no errors', () => {
        setMockPage({ ...base, errors: {} });
        const { container } = render(<ErrorBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('surfaces the first error message with an alert role', () => {
        setMockPage({
            ...base,
            errors: {
                strava: 'Failed to connect Strava. Try again in a bit.',
            },
        });
        render(<ErrorBanner />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Failed to connect Strava',
        );
    });

    it('dismisses when the close button is clicked', () => {
        setMockPage({ ...base, errors: { demo: 'Demo user not seeded yet.' } });
        render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('re-shows the banner when a fresh error message appears after dismissal', () => {
        setMockPage({
            ...base,
            errors: {
                strava: 'Failed to connect Strava. Try again in a bit.',
            },
        });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        setMockPage({ ...base, errors: { demo: 'Demo user not seeded yet.' } });
        rerender(<ErrorBanner />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Demo user not seeded yet.',
        );
    });
});
