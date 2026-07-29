import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StravaPausedBanner from './StravaPausedBanner';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, flash: {}, demoLoginEnabled: false } as const;

describe('StravaPausedBanner', () => {
    it('renders nothing while Strava is enabled', () => {
        setMockPage({ ...base, stravaPaused: false });
        const { container } = render(<StravaPausedBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the prop is absent', () => {
        setMockPage({ ...base });
        const { container } = render(<StravaPausedBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a soft paused message when the kill-switch is off', () => {
        setMockPage({ ...base, stravaPaused: true });
        render(<StravaPausedBanner />);
        expect(
            screen.getByText(
                'Tarikan dari Strava lagi dijeda sebentar. Lari kamu aman kok di Strava, nanti ketarik lagi otomatis.',
            ),
        ).toBeInTheDocument();
    });
});
