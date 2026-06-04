import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import StravaSyncButton from './StravaSyncButton';

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
        expect(router.post).toHaveBeenCalledWith('/strava/sync', {}, { preserveScroll: true });
    });

    it('renders nothing while syncing', () => {
        const { container } = render(<StravaSyncButton state="syncing" />);
        expect(container).toBeEmptyDOMElement();
    });
});
