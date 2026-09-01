import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Race from './Race';

const RACE = {
    id: 1,
    race_date: '2026-12-06',
    distance_m: 10_000,
    goal_time_sec: 3_000,
    name: 'Jakarta 10K',
};

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    exponent: 1.06,
    sample_size: 2,
    confidence: 'medium' as const,
};

describe('Race', () => {
    it('draws the prototype section list in order once a race is set', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        const headings = [
            'Race',
            'your race,',
            'race goal',
            'Jakarta 10K',
            'Projected finish',
            'edit your race',
        ];
        const text = container.textContent ?? '';
        const positions = headings.map((h) => text.indexOf(h));

        expect(positions.every((p) => p >= 0)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    it('swaps the headline and shows the empty state when no race is set', () => {
        render(<Race race={null} projection={null} />);

        expect(screen.getByText(/give the plan/)).toBeInTheDocument();
        expect(
            screen.getByText('no race on the calendar yet.'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Projected finish')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'set race' }),
        ).toBeInTheDocument();
    });

    it('keeps the goal form on the page whether or not a race is set', () => {
        const { rerender } = render(<Race race={null} projection={null} />);
        expect(screen.getByText('set your race')).toBeInTheDocument();

        rerender(<Race race={RACE} projection={PROJECTION} />);
        expect(screen.getByText('edit your race')).toBeInTheDocument();
    });

    it('marks the race tab as current in the schedule switcher', () => {
        render(<Race race={null} projection={null} />);

        expect(screen.getByText('race goal').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('explains there is no projection yet when the race has no PR to anchor from', () => {
        render(<Race race={RACE} projection={null} />);

        expect(screen.getByText(/No personal record yet/)).toBeInTheDocument();
    });

    it('draws no fitness chart — that block is cut (P26)', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        expect(container.querySelector('canvas')).toBeNull();
        expect(screen.queryByText(/CTL|ATL/)).not.toBeInTheDocument();
        expect(screen.queryByText(/^Fitness/i)).not.toBeInTheDocument();
    });

    it('draws Temari only in the projection block when a race is set', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        const faces = container.querySelectorAll('[data-face-icon]');
        expect(faces).toHaveLength(1);
        expect(faces[0]).toHaveAttribute('width', '18');
    });

    it('draws Temari only in the empty state when no race is set', () => {
        const { container } = render(<Race race={null} projection={null} />);

        const faces = container.querySelectorAll('[data-face-icon]');
        expect(faces).toHaveLength(1);
        // 40, as RaceGoalScreen.tsx:226-243 draws it. Only Plan's whole-page
        // empty state takes 48. See PS12.
        expect(faces[0]).toHaveAttribute('width', '40');
    });

    it('shows the saved race summary figures', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('10.0 km')).toBeInTheDocument();
        expect(screen.getByText('50:00')).toBeInTheDocument();
    });
});
