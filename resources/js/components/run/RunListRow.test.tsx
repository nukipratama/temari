import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunListRow from './RunListRow';
import type { ActivityDetail } from '@/types/inertia';

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 1,
        activity_id: 99,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00:00',
        distance: 10000,
        moving_time: 3600,
        average_heartrate: 150,
        trimp_edwards: 70,
        ...overrides,
    };
}

describe('RunListRow', () => {
    it('renders activity name + distance', () => {
        render(<RunListRow detail={detail()} />);
        expect(screen.getByText('Morning Run')).toBeInTheDocument();
        expect(screen.getByText('10.00')).toBeInTheDocument();
    });

    it('falls back to "Run" when name is null', () => {
        render(<RunListRow detail={detail({ name: null })} />);
        expect(screen.getByText('Run')).toBeInTheDocument();
    });

    it('links to /runs/{activity_id}', () => {
        render(<RunListRow detail={detail({ activity_id: 7 })} />);
        expect(screen.getByRole('link').getAttribute('href')).toBe('/runs/7');
    });

    it('renders dashes when numeric fields are null', () => {
        render(
            <RunListRow
                detail={detail({ distance: null, moving_time: null, average_heartrate: null, trimp_edwards: null })}
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('uses dim mood when not provided', () => {
        render(<RunListRow detail={detail()} />);
        expect(screen.getByLabelText('mood dim')).toBeInTheDocument();
    });

    it('uses passed mood when provided', () => {
        render(<RunListRow detail={detail()} mood="glow" />);
        expect(screen.getByLabelText('mood glow')).toBeInTheDocument();
    });
});
