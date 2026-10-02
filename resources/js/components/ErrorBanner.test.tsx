import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { StrictMode } from 'react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    type Mock,
    vi,
} from 'vitest';

import { setMockPage } from '@/test/setup';

import ErrorBanner from './ErrorBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('ErrorBanner', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
    });

    afterEach(() => {
        vi.mocked(router.on).mockImplementation(() => vi.fn());
    });

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

        startVisit();
        setMockPage({ ...base, errors: { demo: 'Demo user not seeded yet.' } });
        rerender(<ErrorBanner />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Demo user not seeded yet.',
        );
    });

    it('re-shows the same message when a new visit fails the same way', () => {
        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));

        startVisit();
        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        rerender(<ErrorBanner />);

        expect(screen.getByRole('alert')).toHaveTextContent('Too far out.');
    });

    it('keeps a dismissed message hidden for the rest of the same visit', () => {
        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        const { rerender } = render(<ErrorBanner />);
        fireEvent.click(screen.getByLabelText('Close'));

        setMockPage({ ...base, errors: { race_date: 'Too far out.' } });
        rerender(<ErrorBanner />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('holds exactly one visit listener while mounted, under StrictMode too, and none after unmount', () => {
        const offs: Mock[] = [];
        vi.mocked(router.on).mockImplementation(() => {
            const off = vi.fn();
            offs.push(off);
            return off;
        });
        const live = () => offs.filter((off) => off.mock.calls.length === 0);

        setMockPage({ ...base, errors: {} });
        const { unmount } = render(
            <StrictMode>
                <ErrorBanner />
            </StrictMode>,
        );
        expect(live()).toHaveLength(1);
        expect(
            vi
                .mocked(router.on)
                .mock.calls.every(([event]) => event === 'start'),
        ).toBe(true);

        unmount();
        expect(live()).toHaveLength(0);
    });
});

function startVisit() {
    const handler = vi
        .mocked(router.on)
        .mock.calls.filter(([event]) => event === 'start')
        .at(-1)?.[1] as (() => void) | undefined;
    act(() => handler?.());
}
