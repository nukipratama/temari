import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import JourneyStrip from './JourneyStrip';

describe('JourneyStrip', () => {
    it('returns null when match is null', () => {
        const { container } = render(<JourneyStrip match={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders heading, total km, and both improvements when present', () => {
        render(
            <JourneyStrip
                match={{
                    first: { date: '2026-01-01', name: 'First', distance_km: 5, pace_sec_per_km: 420, avg_hr: 165 },
                    current: { date: '2026-05-21', name: 'Latest', distance_km: 5, pace_sec_per_km: 360, avg_hr: 150 },
                    pace_improvement_sec: 60,
                    hr_improvement_bpm: 15,
                    total_km: 80.4,
                }}
            />,
        );
        expect(screen.getByText(/Kamu vs Lari Pertama Kamu/i)).toBeInTheDocument();
        expect(screen.getByText(/80\.4 km/)).toBeInTheDocument();
        expect(screen.getByText(/60 detik\/km lebih cepat/)).toBeInTheDocument();
        expect(screen.getByText(/15 bpm lebih rendah/)).toBeInTheDocument();
    });

    it('falls back to the raw iso when formatDate cannot parse it', () => {
        // toLocaleDateString on an invalid Date silently returns 'Invalid Date',
        // it doesn't throw — so we exercise the catch by spying on the prototype.
        const spy = vi.spyOn(Date.prototype, 'toLocaleDateString').mockImplementation(() => {
            throw new Error('locale unavailable');
        });
        render(
            <JourneyStrip
                match={{
                    first: { date: 'not-a-date', name: null, distance_km: null, pace_sec_per_km: null, avg_hr: null },
                    current: { date: null, name: null, distance_km: null, pace_sec_per_km: null, avg_hr: null },
                    pace_improvement_sec: null,
                    hr_improvement_bpm: null,
                    total_km: 0,
                }}
            />,
        );
        expect(screen.getByText(/not-a-date/)).toBeInTheDocument();
        spy.mockRestore();
    });

    it('skips the hr line when no HR data on either side', () => {
        render(
            <JourneyStrip
                match={{
                    first: { date: null, name: null, distance_km: null, pace_sec_per_km: 420, avg_hr: null },
                    current: { date: null, name: null, distance_km: null, pace_sec_per_km: 360, avg_hr: null },
                    pace_improvement_sec: 60,
                    hr_improvement_bpm: null,
                    total_km: 12,
                }}
            />,
        );
        expect(screen.queryByText(/bpm/)).not.toBeInTheDocument();
    });
});
