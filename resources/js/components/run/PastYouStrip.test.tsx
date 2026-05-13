import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PastYouStrip from './PastYouStrip';

describe('PastYouStrip', () => {
    it('renders "first time" copy when match is null', () => {
        render(<PastYouStrip match={null} currentDistance={5000} />);
        expect(screen.getByText(/Pertama kali di 5.0 km/)).toBeInTheDocument();
    });

    it('falls back to "jarak ini" when currentDistance is null', () => {
        render(<PastYouStrip match={null} currentDistance={null} />);
        expect(screen.getByText(/Pertama kali di jarak ini/)).toBeInTheDocument();
    });

    it('renders pace + hr diff when match exists', () => {
        render(
            <PastYouStrip
                match={{
                    past: { start_date_local: '2026-04-01T07:00:00' },
                    pace_diff_sec: 12,
                    hr_diff_bpm: -3,
                    days_ago: 30,
                }}
                currentDistance={5000}
            />,
        );
        expect(screen.getByText(/30 hari lalu/)).toBeInTheDocument();
        expect(screen.getByText(/12 detik\/km lebih cepat/)).toBeInTheDocument();
        expect(screen.getByText(/3 bpm lebih rendah/)).toBeInTheDocument();
    });

    it('handles slower + higher HR with the right tone copy', () => {
        render(
            <PastYouStrip
                match={{
                    past: { start_date_local: null },
                    pace_diff_sec: -10,
                    hr_diff_bpm: 5,
                    days_ago: 7,
                }}
                currentDistance={5000}
            />,
        );
        expect(screen.getByText(/lebih lambat/)).toBeInTheDocument();
        expect(screen.getByText(/lebih tinggi/)).toBeInTheDocument();
    });

    it('hides HR row when hr_diff_bpm is null', () => {
        render(
            <PastYouStrip
                match={{
                    past: { start_date_local: '2026-04-01T07:00:00' },
                    pace_diff_sec: 5,
                    hr_diff_bpm: null,
                    days_ago: 14,
                }}
                currentDistance={5000}
            />,
        );
        expect(screen.queryByText(/bpm/)).not.toBeInTheDocument();
    });
});
