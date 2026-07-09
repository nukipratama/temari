import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StravaZoneReconnectBanner from './StravaZoneReconnectBanner';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, flash: {}, demoLoginEnabled: false } as const;

describe('StravaZoneReconnectBanner', () => {
    it('renders nothing when the scope is not missing', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: false });
        const { container } = render(<StravaZoneReconnectBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the prop is absent', () => {
        setMockPage({ ...base });
        const { container } = render(<StravaZoneReconnectBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a reconnect link when the scope is missing', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        render(<StravaZoneReconnectBanner />);
        expect(screen.getByText(/Sambungin ulang Strava/)).toBeInTheDocument();
        const link = screen.getByText('Sambungin lagi').closest('a');
        expect(link).toHaveAttribute('href', '/auth/strava/redirect');
    });
});
