import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { CalendarCell } from '@/pages/Activities/useCalendar';

import DayRunsSheet from './DayRunsSheet';

function makeCell(overrides: Partial<CalendarCell> = {}): CalendarCell {
    return {
        date: '2026-05-15',
        day: 15,
        is_current_month: true,
        is_today: false,
        distance_km: 7.2,
        pace_sec_per_km: 300,
        avg_hr: null,
        trimp: null,
        mood: null,
        rarity: null,
        activity_id: null,
        effort: 'hard',
        runs: [
            {
                activity_id: 1,
                name: 'morning shakeout',
                distance_km: 3.2,
                pace_sec_per_km: 330,
                effort: 'easy',
                mood: 'chill',
            },
            {
                activity_id: 2,
                name: 'evening intervals',
                distance_km: 4.0,
                pace_sec_per_km: 270,
                effort: 'hard',
                mood: null,
            },
        ],
        ...overrides,
    };
}

describe('DayRunsSheet', () => {
    it('lists each run by name, distance, pace, effort and mood as a word', () => {
        render(<DayRunsSheet cell={makeCell()} onClose={vi.fn()} />);

        expect(screen.getByText('morning shakeout')).toBeInTheDocument();
        expect(screen.getByText('evening intervals')).toBeInTheDocument();
        expect(screen.getByText(/3\.2 km/)).toBeInTheDocument();
        expect(screen.getByText('felt chill')).toBeInTheDocument();
        expect(screen.getByText('hard')).toBeInTheDocument();
        expect(screen.getByText('easy')).toBeInTheDocument();
    });

    it('links each run to its own detail page', () => {
        render(<DayRunsSheet cell={makeCell()} onClose={vi.fn()} />);

        const links = screen.getAllByRole('link');
        expect(links.map((l) => l.getAttribute('href'))).toEqual([
            '/activities/1',
            '/activities/2',
        ]);
    });

    it('renders nothing when there is no cell', () => {
        render(<DayRunsSheet cell={null} onClose={vi.fn()} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('titles the sheet with the date and run count', () => {
        render(<DayRunsSheet cell={makeCell()} onClose={vi.fn()} />);
        expect(
            screen.getByRole('dialog', { name: /2 runs/ }),
        ).toBeInTheDocument();
    });
});
