import type { PlanDay } from '@/lib/plan';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import WeekVolumeChart from './WeekVolumeChart';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-15',
        phase: 'base',
        session_type: 'easy',
        segments: [],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        clamp_note: null,
        actual_km: null,
        activity: null,
        ...overrides,
    };
}

const WEEK: PlanDay[] = [
    day({
        date: '2026-06-15',
        status: 'done',
        compliance_score: 100,
        actual_km: 8,
    }),
    day({
        date: '2026-06-16',
        status: 'missed',
        compliance_score: 20,
        actual_km: 1.6,
    }),
    day({ date: '2026-06-17', session_type: 'rest', distance_km: 0 }),
];

describe('WeekVolumeChart', () => {
    it('reads the week as an adherence percentage over its scored days', () => {
        render(<WeekVolumeChart days={WEEK} />);

        expect(screen.getByText('60%')).toBeInTheDocument();
    });

    it('tallies only the verdicts that actually occurred', () => {
        render(<WeekVolumeChart days={WEEK} />);

        expect(screen.getByText('1 done · 1 missed')).toBeInTheDocument();
    });

    it('says "this week" and "so far" only for the current week', () => {
        const { rerender } = render(<WeekVolumeChart days={WEEK} />);
        expect(screen.getByText('Volume that week')).toBeInTheDocument();

        rerender(<WeekVolumeChart days={WEEK} isCurrent />);
        expect(screen.getByText('Volume this week')).toBeInTheDocument();
        expect(
            screen.getByText('1 done · 1 missed so far'),
        ).toBeInTheDocument();
    });

    it('labels every day of the week by its weekday', () => {
        render(<WeekVolumeChart days={WEEK} />);

        expect(screen.getByText('Mon')).toBeInTheDocument();
        expect(screen.getByText('Tue')).toBeInTheDocument();
        expect(screen.getByText('Wed')).toBeInTheDocument();
    });

    it('draws an actual bar only for days that logged something', () => {
        const { container } = render(<WeekVolumeChart days={WEEK} />);

        // Two planned outlines (the rest day is 0 km) plus two actual fills.
        expect(container.querySelectorAll('.border-dashed')).toHaveLength(3);
        expect(container.querySelectorAll('.absolute')).toHaveLength(4);
    });

    it('scales both bars against the week’s biggest day', () => {
        const { container } = render(<WeekVolumeChart days={WEEK} />);

        const bars = container.querySelectorAll('.absolute');
        expect(bars[0]).toHaveStyle({ height: '100%' });
        expect(bars[1]).toHaveStyle({ height: '100%' });
        expect(bars[3]).toHaveStyle({ height: '20%' });
    });

    it('shows no percentage for a week that has not been scored yet', () => {
        render(<WeekVolumeChart days={[day(), day({ date: '2026-06-16' })]} />);

        expect(screen.queryByText(/%$/)).not.toBeInTheDocument();
    });
});
