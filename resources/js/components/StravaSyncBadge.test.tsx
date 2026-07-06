import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StravaSyncBadge from './StravaSyncBadge';
import type { StravaSync } from '@/types/inertia';

function sync(state: StravaSync['state'], lastSyncedAt: string | null = null): StravaSync {
    return { state, last_synced_at: lastSyncedAt };
}

describe('StravaSyncBadge', () => {
    it('renders an inert badge when disconnected', () => {
        render(<StravaSyncBadge sync={sync('disconnected')} />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders an inert badge while syncing', () => {
        render(<StravaSyncBadge sync={sync('syncing')} />);
        expect(screen.getByText('Lagi sinkron')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders an inert badge when ready', () => {
        render(<StravaSyncBadge sync={sync('ready', '2026-07-04T00:00:00Z')} />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText(/^Strava synced/)).toBeInTheDocument();
        expect(screen.getByLabelText(/^Strava synced/)).toBeInTheDocument();
    });

    it('uses the compact label variant when density is compact', () => {
        render(<StravaSyncBadge sync={sync('syncing')} density="compact" />);
        expect(screen.getByText('Sinkron')).toBeInTheDocument();
        expect(screen.queryByText('Lagi sinkron')).not.toBeInTheDocument();
    });

    it('renders a reconnect link to the OAuth redirect when revoked', () => {
        render(<StravaSyncBadge sync={sync('revoked')} />);
        const link = screen.getByRole('link', { name: /sambungkan ulang/i });
        expect(link).toHaveAttribute('href', '/auth/strava/redirect');
        expect(screen.getByText(/Sambungkan ulang/)).toBeInTheDocument();
    });

    it('defaults a missing sync prop to disconnected', () => {
        render(<StravaSyncBadge sync={null} />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
