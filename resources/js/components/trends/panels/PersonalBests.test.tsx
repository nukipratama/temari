import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PersonalBests, {
    type DistanceRecord,
    type PaceRecord,
} from './PersonalBests';

describe('PersonalBests', () => {
    it('shows a first-PR prompt when the user has no records yet', () => {
        render(<PersonalBests distanceRecords={[]} paceRecords={[]} />);

        expect(
            screen.getByText(/Run to set your first personal best/),
        ).toBeInTheDocument();
    });

    it('renders a tile per distance record with its pace and date', () => {
        const distanceRecords: DistanceRecord[] = [
            {
                category: '5km',
                label: '5 km',
                distanceM: 5000,
                valueSec: 1500,
                setAt: '2026-06-01',
            },
        ];
        render(
            <PersonalBests
                distanceRecords={distanceRecords}
                paceRecords={[]}
            />,
        );

        expect(screen.getByText('5 km')).toBeInTheDocument();
        expect(screen.getByText('25:00')).toBeInTheDocument();
        expect(screen.getByText(/5:00\/km/)).toBeInTheDocument();
    });

    it('renders a list row per pace record with its pace and date', () => {
        const paceRecords: PaceRecord[] = [
            {
                category: 'best_5min',
                label: 'Best 5 min',
                paceSec: 220,
                setAt: '2026-06-01',
            },
        ];
        render(
            <PersonalBests distanceRecords={[]} paceRecords={paceRecords} />,
        );

        expect(screen.getByText('Best 5 min')).toBeInTheDocument();
        expect(screen.getByText('3:40/km')).toBeInTheDocument();
    });

    it('omits a sub-section entirely when it has no records', () => {
        const distanceRecords: DistanceRecord[] = [
            {
                category: '5km',
                label: '5 km',
                distanceM: 5000,
                valueSec: 1500,
                setAt: '2026-06-01',
            },
        ];
        render(
            <PersonalBests
                distanceRecords={distanceRecords}
                paceRecords={[]}
            />,
        );

        expect(
            screen.queryByText('Best effort by time'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Run to set your first personal best/),
        ).not.toBeInTheDocument();
    });
});
