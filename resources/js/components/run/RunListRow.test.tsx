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

    it('links to /aktivitas/{activity_id}', () => {
        render(<RunListRow detail={detail({ activity_id: 7 })} />);
        expect(screen.getByRole('link').getAttribute('href')).toBe('/aktivitas/7');
    });

    it('renders dashes when numeric fields are null', () => {
        render(
            <RunListRow
                detail={detail({ distance: null, moving_time: null, average_heartrate: null, trimp_edwards: null })}
            />,
        );
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('derives a mood from TRIMP when none is provided', () => {
        // TRIMP=70 (default fixture) falls in the `nyala` aerobic bucket.
        // Mood surfaces as the MoodChip label text now (the Temari aria-label was dropped in the Badge refactor).
        render(<RunListRow detail={detail()} />);
        expect(screen.getByText('Nyala')).toBeInTheDocument();
    });

    it('uses passed mood when provided (overrides derivation)', () => {
        // TRIMP=70 would derive `nyala`, but the explicit `mood` prop wins.
        render(<RunListRow detail={detail()} mood="adem" />);
        expect(screen.getByText('Adem')).toBeInTheDocument();
        expect(screen.queryByText('Nyala')).not.toBeInTheDocument();
    });

    it('derives lemes for a crushing TRIMP', () => {
        render(<RunListRow detail={detail({ trimp_edwards: 220 })} />);
        expect(screen.getByText('Lemes')).toBeInTheDocument();
    });

    it('renders **bold** markers in the note as <strong>', () => {
        render(<RunListRow detail={detail()} note={{ oneline: 'dapet **PR** juga', mood: 'nyala' }} />);
        const strong = screen.getByText('PR');
        expect(strong.tagName).toBe('STRONG');
    });
});
