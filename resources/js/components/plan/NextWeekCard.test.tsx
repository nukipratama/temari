import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import NextWeekCard, { type NextWeekOutlook } from './NextWeekCard';

const set: NextWeekOutlook = {
    state: 'set',
    egregious: false,
    hot_days: [
        { date: '2026-11-17', session_type: 'easy', over_cap_minutes: 9 },
        { date: '2026-11-21', session_type: 'long', over_cap_minutes: 14 },
    ],
    drop: { date: '2026-11-26', session_type: 'tempo', time_trial: false },
    blocker: null,
};

describe('NextWeekCard', () => {
    it('names the day next week loses, the runs that cost it, and that it can come back', () => {
        render(<NextWeekCard outlook={set} />);

        const card = screen.getByRole('region', {
            name: 'next week, as of now',
        });
        expect(card).toHaveTextContent(/thu\s*tempo\s*→\s*easy/i);
        expect(card).toHaveTextContent('you can bring it back on the day.');
        expect(card).toHaveTextContent(
            'over your heart-rate cap: tue easy 9 min, sat long run 14 min.',
        );
        expect(card).toHaveTextContent("this week's runs can't undo it.");
        expect(screen.queryByText('at risk')).not.toBeInTheDocument();
    });

    it('names the rule that keeps a session near the race', () => {
        render(<NextWeekCard outlook={{ ...set, blocker: 'race week' }} />);

        expect(
            screen.getByText("can't bring back: race week"),
        ).toBeInTheDocument();
        expect(screen.queryByText(/bring it back on the day/)).toBeNull();
    });

    it('marks a session one more hot day away as at risk', () => {
        render(
            <NextWeekCard
                outlook={{
                    ...set,
                    state: 'one_more',
                    hot_days: set.hot_days.slice(0, 1),
                }}
            />,
        );

        expect(screen.getByText('at risk')).toBeInTheDocument();
        expect(
            screen.getByText('you could bring it back on the day.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/one more easy day over it this week makes it so/),
        ).toBeInTheDocument();
    });

    it('names a time trial by its own label', () => {
        render(
            <NextWeekCard
                outlook={{
                    ...set,
                    drop: { ...set.drop, time_trial: true },
                }}
            />,
        );

        expect(screen.getByText('time trial')).toBeInTheDocument();
    });
});
