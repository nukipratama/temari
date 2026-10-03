import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import StravaZoneReconnectBanner from './StravaZoneReconnectBanner';

const base = {
    auth: { user: makeUser({ id: 42 }) },
    flash: {},
    demoLoginEnabled: false,
} as const;

beforeEach(() => {
    window.sessionStorage.clear();
});

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

    it('names the missing scope and what falls back without it', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        render(<StravaZoneReconnectBanner />);

        expect(
            screen.getByText(
                /Strava only shares your HR zones with the profile scope, which this connection is missing, so anything zone-based falls back to estimates until you reconnect\./,
            ),
        ).toBeInTheDocument();
    });

    it('shows a reconnect link when the scope is missing', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        render(<StravaZoneReconnectBanner />);

        const link = screen.getByText('reconnect').closest('a');
        expect(link).toHaveAttribute(
            'href',
            '/auth/strava/redirect?from=/profile',
        );
    });

    it('hides itself for the rest of the session once dismissed', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        const first = render(<StravaZoneReconnectBanner />);

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(first.container.firstChild).toBeNull();
        expect(
            window.sessionStorage.getItem('strava-zone-reconnect-dismissed:42'),
        ).toBe('1');

        first.unmount();
        const second = render(<StravaZoneReconnectBanner />);
        expect(second.container.firstChild).toBeNull();
    });

    it('keeps the dismissal keyed per user', () => {
        window.sessionStorage.setItem(
            'strava-zone-reconnect-dismissed:42',
            '1',
        );
        setMockPage({
            auth: { user: makeUser({ id: 7 }) },
            flash: {},
            demoLoginEnabled: false,
            stravaZoneScopeMissing: true,
        });

        render(<StravaZoneReconnectBanner />);
        expect(screen.getByText('reconnect')).toBeInTheDocument();
    });

    it('comes back on the next visit', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        const first = render(<StravaZoneReconnectBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        first.unmount();

        window.sessionStorage.clear();
        render(<StravaZoneReconnectBanner />);
        expect(screen.getByText('reconnect')).toBeInTheDocument();
    });
});

describe('StravaZoneReconnectBanner column cap', () => {
    it('caps the column only from 900px up', () => {
        setMockPage({ ...base, stravaZoneScopeMissing: true });
        const { container } = render(<StravaZoneReconnectBanner />);
        const box = container.querySelector('[class*="max-w-column"]')!;
        expect(box.classList.contains('max-w-column')).toBe(false);
        expect(box.classList.contains('min-[900px]:max-w-column')).toBe(true);
    });
});
