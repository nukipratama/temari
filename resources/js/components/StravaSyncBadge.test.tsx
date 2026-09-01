import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StravaSync } from '@/types/inertia';

import StravaSyncBadge from './StravaSyncBadge';

function sync(
    state: StravaSync['state'],
    lastsyncedAt: string | null = null,
): StravaSync {
    return { state, last_synced_at: lastsyncedAt };
}

describe('StravaSyncBadge', () => {
    it('renders an inert badge when disconnected', () => {
        render(<StravaSyncBadge sync={sync('disconnected')} />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders an inert badge while syncing', () => {
        render(<StravaSyncBadge sync={sync('syncing')} />);
        expect(screen.getByText('syncing')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders an inert badge when ready', () => {
        render(
            <StravaSyncBadge sync={sync('ready', '2026-07-04T00:00:00Z')} />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText(/^Strava synced/)).toBeInTheDocument();
        expect(screen.getByLabelText(/^Strava synced/)).toBeInTheDocument();
    });

    it('uses the compact label variant when density is compact', () => {
        render(<StravaSyncBadge sync={sync('syncing')} density="compact" />);
        expect(screen.getByText('sync')).toBeInTheDocument();
        expect(screen.queryByText('syncing')).not.toBeInTheDocument();
    });

    it('renders a reconnect link to the OAuth redirect when revoked', () => {
        render(<StravaSyncBadge sync={sync('revoked')} />);
        const link = screen.getByRole('link', { name: /reconnect/i });
        expect(link).toHaveAttribute('href', '/auth/strava/redirect');
        expect(screen.getByText(/reconnect/)).toBeInTheDocument();
    });

    it('defaults a missing sync prop to disconnected', () => {
        render(<StravaSyncBadge sync={null} />);
        expect(screen.getByText('Strava')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
