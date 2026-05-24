import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import WeekVsLastWeek from './WeekVsLastWeek';

describe('WeekVsLastWeek', () => {
    it('returns null when data is null', () => {
        const { container } = render(<WeekVsLastWeek data={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders this-week totals + signed deltas', () => {
        render(
            <WeekVsLastWeek
                data={{
                    distance_delta_km: 3.5,
                    runs_delta: 1,
                    pace_delta_sec: -4,
                    this_week_km: 18.2,
                    this_week_runs: 3,
                }}
            />,
        );
        expect(screen.getByText(/18\.2 km/)).toBeInTheDocument();
        expect(screen.getByText(/3 lari/)).toBeInTheDocument();
        expect(screen.getByText(/\+3\.5 km/)).toBeInTheDocument();
        expect(screen.getByText(/\+1 lari/)).toBeInTheDocument();
        expect(screen.getByText(/4 detik\/km lebih cepat/)).toBeInTheDocument();
    });

    it('paints distance delta in the cooked tone when this-week < last-week', () => {
        render(
            <WeekVsLastWeek
                data={{
                    distance_delta_km: -2,
                    runs_delta: 0,
                    pace_delta_sec: null,
                    this_week_km: 5,
                    this_week_runs: 1,
                }}
            />,
        );
        const span = screen.getByText(/2\.0 km lebih sedikit/);
        expect(span.className).toContain('text-mood-lemes');
    });

    it('hides the pace row when pace_delta_sec is null', () => {
        render(
            <WeekVsLastWeek
                data={{
                    distance_delta_km: 0,
                    runs_delta: 0,
                    pace_delta_sec: null,
                    this_week_km: 0,
                    this_week_runs: 0,
                }}
            />,
        );
        expect(screen.queryByText(/detik\/km/)).not.toBeInTheDocument();
    });
});
