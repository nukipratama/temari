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

    it('re-shows the banner when a later response carries a different error', () => {
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

    it('re-shows the same message when a new response fails the same way', () => {
        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));

        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        rerender(<ErrorBanner />);

        expect(screen.getByRole('alert')).toHaveTextContent('Too far out.');
    });

    it('keeps a dismissed message hidden while the page still holds that response', () => {
        const errors = { race_date: 'Too far out.' };
        setMockPage({ ...base, errors });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));

        setMockPage({ ...base, errors });
        rerender(<ErrorBanner />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});

describe('ErrorBanner column cap', () => {
    it('caps the column only from 900px up', () => {
        setMockPage({ ...base, errors: { strava: 'Failed.' } });
        const { container } = render(<ErrorBanner />);
        const box = container.querySelector('[class*="max-w-column"]')!;
        expect(box.classList.contains('max-w-column')).toBe(false);
        expect(box.classList.contains('min-[900px]:max-w-column')).toBe(true);
    });
});
