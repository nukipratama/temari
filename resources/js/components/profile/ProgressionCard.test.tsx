import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ProgressionCard from './ProgressionCard';

vi.mock('@/components/profile/JourneyChart', () => ({
    default: () => <div data-testid="journey-chart" />,
}));

const BY_CATEGORY = {
    '5km': {
        category: '5km',
        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
        times_sec: [1800, 1770, 1751],
        goal_sec: 1740,
    },
    '10km': {
        category: '10km',
        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
        times_sec: [3800, 3770, 3751],
        goal_sec: null,
    },
};

describe('ProgressionCard', () => {
    it('opens on the longest distance available and draws its journey', () => {
        render(<ProgressionCard byCategory={BY_CATEGORY} />);

        expect(screen.getByText(/Journey · 10 km/)).toBeInTheDocument();
        expect(screen.getByTestId('journey-chart')).toBeInTheDocument();
    });

    it('switches distance when another pill is chosen', () => {
        render(<ProgressionCard byCategory={BY_CATEGORY} />);
        const tablist = screen.getByRole('tablist', {
            name: 'Choose distance',
        });

        fireEvent.click(within(tablist).getByRole('tab', { name: '5K' }));

        expect(
            within(tablist).getByRole('tab', { name: '5K' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByText(/Journey · 5 km/)).toBeInTheDocument();
    });

    it('offers no pills when only one distance has times', () => {
        render(<ProgressionCard byCategory={{ '5km': BY_CATEGORY['5km'] }} />);

        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    });

    it('shows the goal chip only for a distance that has one', () => {
        render(<ProgressionCard byCategory={BY_CATEGORY} />);
        expect(screen.queryByText(/goal: sub-/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: '5K' }));
        expect(screen.getByText(/goal: sub-29:00/)).toBeInTheDocument();
    });
});
