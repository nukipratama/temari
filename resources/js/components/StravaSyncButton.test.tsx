import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import StravaSyncButton from './StravaSyncButton';
import { setMockPage } from '@/test/setup';

describe('StravaSyncButton', () => {
    it('renders a connect link to the OAuth redirect when disconnected', () => {
        render(<StravaSyncButton state="disconnected" />);
        expect(screen.getByText('Sambungin Strava').closest('a')).toHaveAttribute('href', '/auth/strava/redirect');
    });

    it('renders a reconnect link when revoked', () => {
        render(<StravaSyncButton state="revoked" />);
        expect(screen.getByText('Sambungin lagi').closest('a')).toHaveAttribute('href', '/auth/strava/redirect');
    });

    it('posts to /strava/sync when ready and clicked', () => {
        vi.mocked(router.post).mockReset();
        render(<StravaSyncButton state="ready" />);
        fireEvent.click(screen.getByText('Sync sekarang'));
        expect(router.post).toHaveBeenCalledWith(
            '/strava/sync',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('disables the button and relabels while the sync request is in flight', () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });
        render(<StravaSyncButton state="ready" />);
        fireEvent.click(screen.getByText('Sync sekarang'));

        const button = screen.getByRole('button', { name: 'Menyinkron…' });
        expect(button).toBeDisabled();
    });

    it('re-enables the button once the sync request finishes', () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
            options?.onFinish?.({} as never);
        });
        render(<StravaSyncButton state="ready" />);
        fireEvent.click(screen.getByText('Sync sekarang'));

        expect(screen.getByRole('button', { name: 'Sync sekarang' })).not.toBeDisabled();
    });

    it('hides the sync button while the Strava kill-switch is off', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false, stravaPaused: true });
        const { container } = render(<StravaSyncButton state="ready" />);
        expect(container).toBeEmptyDOMElement();
    });

    it('keeps the connect link while paused, since OAuth still completes', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false, stravaPaused: true });
        render(<StravaSyncButton state="disconnected" />);
        expect(screen.getByText('Sambungin Strava').closest('a')).toHaveAttribute('href', '/auth/strava/redirect');
    });

    it('renders nothing while syncing', () => {
        const { container } = render(<StravaSyncButton state="syncing" />);
        expect(container).toBeEmptyDOMElement();
    });
});
