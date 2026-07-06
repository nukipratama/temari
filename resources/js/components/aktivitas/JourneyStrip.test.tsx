import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
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
        const paceSpan = screen.getByText(/60 detik\/km lebih cepat/);
        expect(paceSpan).toBeInTheDocument();
        expect(paceSpan).toHaveClass('text-leaf-deep');
        const hrSpan = screen.getByText(/15 bpm lebih rendah/);
        expect(hrSpan).toBeInTheDocument();
        expect(hrSpan).toHaveClass('text-leaf-deep');
    });

    it('renders the negative-tone copy and class when pace/HR got worse', () => {
        render(
            <JourneyStrip
                match={{
                    first: { date: '2026-01-01', name: 'First', distance_km: 5, pace_sec_per_km: 360, avg_hr: 150 },
                    current: { date: '2026-05-21', name: 'Latest', distance_km: 5, pace_sec_per_km: 420, avg_hr: 165 },
                    pace_improvement_sec: -10,
                    hr_improvement_bpm: -5,
                    total_km: 80.4,
                }}
            />,
        );
        const paceSpan = screen.getByText(/10 detik\/km lebih lambat/);
        expect(paceSpan).toBeInTheDocument();
        expect(paceSpan).toHaveClass('text-ember-deep');
        const hrSpan = screen.getByText(/5 bpm lebih tinggi/);
        expect(hrSpan).toBeInTheDocument();
        expect(hrSpan).toHaveClass('text-ember-deep');
    });

    it('formats the first-run date as a wall-clock short date (RunController sends a date-only string)', () => {
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
        expect(screen.getByText('1 Jan 2026')).toBeInTheDocument();
    });

    it('falls back to the raw iso when the date string is unparseable', () => {
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
        expect(screen.queryByText(/detik\/km/)).not.toBeInTheDocument();
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
