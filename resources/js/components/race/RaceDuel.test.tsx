import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import RaceDuel from './RaceDuel';

const RACE = {
    name: 'Jakarta 10K',
    race_date: '2026-12-06',
    goal_time_sec: 3_000,
};

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    sample_size: 2,
    confidence: 'medium' as const,
    window: 'recent' as const,
};

describe('RaceDuel', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2026, 10, 26, 9, 0));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('faces the goal off against the projection with the gap in words', () => {
        render(<RaceDuel race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('your goal')).toBeInTheDocument();
        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(screen.getByText('on track for')).toBeInTheDocument();
        expect(screen.getByText('51:40')).toBeInTheDocument();
        expect(screen.getByText('1:40 behind')).toHaveClass('text-ember-ink');
    });

    it('draws an ahead gap in the leaf family', () => {
        render(
            <RaceDuel
                race={RACE}
                projection={{ ...PROJECTION, predicted_sec: 2_870 }}
            />,
        );

        expect(screen.getByText('2:10 ahead')).toHaveClass('text-leaf-ink');
    });

    it('says on goal when the projection sits within a few seconds', () => {
        render(
            <RaceDuel
                race={RACE}
                projection={{ ...PROJECTION, predicted_sec: 3_003 }}
            />,
        );

        expect(screen.getByText('on goal')).toBeInTheDocument();
    });

    it('poses the watermark from the gap', () => {
        const { container } = render(
            <RaceDuel
                race={RACE}
                projection={{ ...PROJECTION, predicted_sec: 3_300 }}
            />,
        );

        expect(container.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'gassed',
        );
    });

    it('carries the race line and what the projection rests on', () => {
        render(<RaceDuel race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('Jakarta 10K')).toBeInTheDocument();
        expect(screen.getByText(/· 10 days to go/)).toBeInTheDocument();
        expect(
            screen.getByText(
                /from 2 PRs in the last 4 months \(moderate range\)/,
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('img')).toBeInTheDocument();
    });

    it('says "1 PR" and names the whole record when that is what it rests on', () => {
        render(
            <RaceDuel
                race={{ ...RACE, name: null }}
                projection={{ ...PROJECTION, sample_size: 1, window: 'all' }}
            />,
        );

        expect(screen.getByText('your race')).toBeInTheDocument();
        expect(
            screen.getByText(/from 1 PR across your whole record/),
        ).toBeInTheDocument();
    });

    it('shows the goal alone with a neutral Temari when there is no projection', () => {
        const { container } = render(
            <RaceDuel race={RACE} projection={null} />,
        );

        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(
            screen.getByText('not enough recent runs to project yet'),
        ).toBeInTheDocument();
        expect(screen.queryByText('on track for')).not.toBeInTheDocument();
        expect(
            screen.queryByText(/behind|ahead|on goal/),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.queryByText(/best estimate/)).not.toBeInTheDocument();
        expect(container.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'neutral',
        );
    });
});
