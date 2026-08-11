import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import StravaPausedBanner from './StravaPausedBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

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
                "The pull from Strava is paused for a bit. Your runs are safe on Strava, they'll pull back in automatically.",
            ),
        ).toBeInTheDocument();
    });
});
