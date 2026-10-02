import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ProgressionCard, { type ProgressionSeries } from './ProgressionCard';

vi.mock('@/components/profile/JourneyChart', () => ({
    default: () => <div data-testid="journey-chart" />,
}));

const BY_CATEGORY = {
    '5km': {
        category: '5km',
        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
        times_sec: [1800, 1770, 1751],
        activity_ids: [101, 102, 103],
        goal_sec: 1740,
        progress: null,
    },
    '10km': {
        category: '10km',
        weeks: ['2026-04-13', '2026-04-20', '2026-04-27'],
        times_sec: [3800, 3770, 3751],
        activity_ids: [201, 202, 203],
        goal_sec: null,
        progress: {
            relation: 'faster',
            delta_sec: 49,
            from_sec: 3800,
            to_sec: 3751,
            weeks: 2,
        },
    },
} satisfies Record<string, ProgressionSeries>;

describe('ProgressionCard', () => {
    it('opens on the longest distance available and draws its journey', () => {
        render(<ProgressionCard byCategory={BY_CATEGORY} />);

        expect(screen.getByText(/Journey · 10 km/)).toBeInTheDocument();
        expect(screen.getByTestId('journey-chart')).toBeInTheDocument();
        expect(
            screen.getByText('“0:49 faster over 2 weeks.”'),
        ).toBeInTheDocument();
        expect(screen.getByText('−0:49 total')).toBeInTheDocument();
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

    it('reads a regressing series as slower and never as faster', () => {
        render(
            <ProgressionCard
                byCategory={{
                    '5km': {
                        category: '5km',
                        weeks: ['2026-04-06', '2026-08-24', '2026-09-28'],
                        times_sec: [1500, 1980, 1980],
                        activity_ids: [1, 2, 3],
                        goal_sec: null,
                        progress: {
                            relation: 'slower',
                            delta_sec: 480,
                            from_sec: 1500,
                            to_sec: 1980,
                            weeks: 25,
                        },
                    },
                }}
            />,
        );

        expect(screen.queryByText(/faster/)).not.toBeInTheDocument();
        expect(
            screen.getByText('“8:00 slower over 25 weeks.”'),
        ).toBeInTheDocument();
        expect(screen.getByText('+8:00 total')).toBeInTheDocument();
        expect(screen.getByText('from 25:00')).toBeInTheDocument();
    });

    it('reads a flat series as holding steady, with no total', () => {
        render(
            <ProgressionCard
                byCategory={{
                    '5km': {
                        category: '5km',
                        weeks: ['2026-04-06', '2026-09-28'],
                        times_sec: [1500, 1490],
                        activity_ids: [1, 2],
                        goal_sec: null,
                        progress: {
                            relation: 'flat',
                            delta_sec: 10,
                            from_sec: 1500,
                            to_sec: 1490,
                            weeks: 25,
                        },
                    },
                }}
            />,
        );

        expect(screen.queryByText(/faster|slower/)).not.toBeInTheDocument();
        expect(
            screen.getByText('“holding steady over 25 weeks.”'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/total/)).not.toBeInTheDocument();
    });

    it('shows no figure when the series has no progress reading', () => {
        render(
            <ProgressionCard
                byCategory={{
                    '5km': { ...BY_CATEGORY['5km'], progress: null },
                }}
            />,
        );

        expect(
            screen.queryAllByText(/faster|slower|steady|total|from/),
        ).toHaveLength(0);
        expect(screen.getByTestId('journey-chart')).toBeInTheDocument();
    });

    it('shows the goal chip only for a distance that has one', () => {
        render(<ProgressionCard byCategory={BY_CATEGORY} />);
        expect(screen.queryByText(/goal: sub-/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: '5K' }));
        expect(screen.getByText(/goal: sub-29:00/)).toBeInTheDocument();
    });
});
