import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import History from './History';

vi.mock('./Activities/Feed', () => ({
    default: (props: Record<string, unknown>) => (
        <div data-testid="feed">{JSON.stringify(props)}</div>
    ),
}));
vi.mock('./Activities/Calendar', () => ({
    default: (props: Record<string, unknown>) => (
        <div data-testid="calendar">{JSON.stringify(props)}</div>
    ),
}));

describe('History', () => {
    it('renders Feed for the list view', () => {
        render(
            <History
                activeView="list"
                runs={[]}
                rangeFilter="8w"
                rangeStart={null}
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByTestId('feed')).toBeInTheDocument();
        expect(screen.queryByTestId('calendar')).not.toBeInTheDocument();
    });

    it('renders Calendar for the calendar view', () => {
        render(
            <History
                activeView="calendar"
                cells={[]}
                month="2026-06"
                monthLabel="June 2026"
                prevMonth="2026-05"
                nextMonth="2026-07"
                todayMonth="2026-06"
            />,
        );

        expect(screen.getByTestId('calendar')).toBeInTheDocument();
        expect(screen.queryByTestId('feed')).not.toBeInTheDocument();
    });

    it('passes every prop through to the active view unchanged', () => {
        render(
            <History
                activeView="list"
                runs={[]}
                rangeFilter="8w"
                rangeStart={null}
                weeklySnapshots={[]}
            />,
        );

        expect(screen.getByTestId('feed').textContent).toContain(
            '"activeView":"list"',
        );
    });
});
