import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import type { PlanSessionSegment } from '@/types/inertia';

import DaySegments from './DaySegments';

const SEGMENTS: PlanSessionSegment[] = [
    {
        key: 'warmup',
        minutes: 5,
        zone: 'Z1',
        pace_label: 'easy',
        pace_sec_per_km: 360,
    },
    {
        key: 'main',
        minutes: 35,
        zone: 'Z2',
        pace_label: 'marathon',
        pace_sec_per_km: 330,
    },
    {
        key: 'cooldown',
        minutes: 5,
        zone: 'Z1',
        pace_label: 'easy',
        pace_sec_per_km: 360,
    },
];

describe('DaySegments', () => {
    it('renders nothing for a rest day with no segments', () => {
        const { container } = render(<DaySegments segments={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('keeps the segment breakdown collapsed until toggled', async () => {
        render(<DaySegments segments={SEGMENTS} />);

        expect(screen.queryByText('Main set')).not.toBeInTheDocument();

        await userEvent.setup().click(screen.getByText('Segments'));

        expect(screen.getByText('Main set')).toBeInTheDocument();
        expect(screen.getByText('35 min')).toBeInTheDocument();
        expect(screen.getByText('5:30/km')).toBeInTheDocument();
    });

    it('labels every segment key in Title Case', async () => {
        render(<DaySegments segments={SEGMENTS} />);

        await userEvent.setup().click(screen.getByText('Segments'));

        expect(screen.getAllByText('Warmup')).toHaveLength(1);
        expect(screen.getByText('Cooldown')).toBeInTheDocument();
    });

    it('omits the pace when a segment has no VDOT-derived target', async () => {
        render(
            <DaySegments
                segments={[
                    {
                        key: 'main',
                        minutes: 20,
                        zone: 'Z3',
                        pace_label: 'threshold',
                        pace_sec_per_km: null,
                    },
                ]}
            />,
        );

        await userEvent.setup().click(screen.getByText('Segments'));

        expect(screen.getByText('20 min')).toBeInTheDocument();
        expect(screen.queryByText(/\/km/)).not.toBeInTheDocument();
    });
});
