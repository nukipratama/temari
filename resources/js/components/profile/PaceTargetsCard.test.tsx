import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PaceTargetsCard, { type WeekSession } from './PaceTargetsCard';

const PACES = { easy: 370, marathon: 320, threshold: 292, interval: 268 };

const WEEK: WeekSession[] = [
    { weekday: 'mon', session_type: 'easy', distance_km: 6.4, is_today: false },
    { weekday: 'wed', session_type: 'tempo', distance_km: 9.8, is_today: true },
    { weekday: 'sat', session_type: 'easy', distance_km: 7.2, is_today: false },
    {
        weekday: 'sun',
        session_type: 'long',
        distance_km: 16.4,
        is_today: false,
    },
];

/** The server flags the day it is on; a week with none is a day off the plan. */
const WEEK_OFF_PLAN: WeekSession[] = WEEK.map((session) => ({
    ...session,
    is_today: false,
}));

function fills(container: HTMLElement): number[] {
    return [
        ...container.querySelectorAll<HTMLElement>('div[style*="width"]'),
    ].map((bar) => Number.parseFloat(bar.style.width));
}

describe('PaceTargetsCard', () => {
    it('lists the four targets slowest first, with their formatted pace', () => {
        render(<PaceTargetsCard paces={PACES} />);

        const rungs = screen.getAllByRole('listitem');

        expect(rungs).toHaveLength(4);
        expect(rungs[0]).toHaveTextContent('easy');
        expect(rungs[0]).toHaveTextContent('6:10');
        expect(rungs[1]).toHaveTextContent('marathon');
        expect(rungs[2]).toHaveTextContent('tempo');
        expect(rungs[3]).toHaveTextContent('interval');
        expect(rungs[3]).toHaveTextContent('4:28');
    });

    it('describes each pace in words when the week holds no plan', () => {
        render(<PaceTargetsCard paces={PACES} />);

        expect(screen.getByText('most of your runs')).toBeInTheDocument();
        expect(screen.getByText('long steady efforts')).toBeInTheDocument();
        expect(
            screen.getByText('comfortably hard, 20–40 min'),
        ).toBeInTheDocument();
        expect(screen.getByText('short hard reps')).toBeInTheDocument();
    });

    it('names the days this week asks for each pace, with the distance of a single one', () => {
        render(<PaceTargetsCard paces={PACES} weekSessions={WEEK} />);

        expect(screen.getByText('mon, sat')).toBeInTheDocument();
        expect(screen.getByText('sun · 16k')).toBeInTheDocument();
        expect(screen.getByText('wed · 10k')).toBeInTheDocument();
        expect(screen.getByText('none this week')).toBeInTheDocument();
    });

    it('leaves the distance off a day too short to round to a kilometre', () => {
        render(
            <PaceTargetsCard
                paces={PACES}
                weekSessions={[
                    {
                        weekday: 'tue',
                        session_type: 'interval',
                        distance_km: 0,
                        is_today: false,
                    },
                ]}
            />,
        );

        expect(screen.getByText('tue')).toBeInTheDocument();
    });

    it('accents the rung today is run at, and only that one', () => {
        const { container } = render(
            <PaceTargetsCard paces={PACES} weekSessions={WEEK} />,
        );

        const accented =
            container.querySelectorAll<HTMLElement>('.bg-icon-accent');

        expect(accented).toHaveLength(1);
        expect(screen.getAllByRole('listitem')[2]).toContainElement(
            accented[0],
        );
    });

    it('accents nothing when today is off the plan', () => {
        const { container } = render(
            <PaceTargetsCard paces={PACES} weekSessions={WEEK_OFF_PLAN} />,
        );

        expect(container.querySelectorAll('.bg-icon-accent')).toHaveLength(0);
    });

    it('accents nothing when today prescribes a session no pace answers to', () => {
        const { container } = render(
            <PaceTargetsCard
                paces={PACES}
                weekSessions={[
                    {
                        weekday: 'wed',
                        session_type: 'race',
                        distance_km: 21.1,
                        is_today: true,
                    },
                ]}
            />,
        );

        expect(container.querySelectorAll('.bg-icon-accent')).toHaveLength(0);
    });

    it('fills each bar by where its pace sits between the slowest and the fastest', () => {
        const { container } = render(<PaceTargetsCard paces={PACES} />);

        const [easy, marathon, tempo, interval] = fills(container);

        expect(easy).toBe(12);
        expect(interval).toBe(100);
        expect(marathon).toBeGreaterThan(easy);
        expect(tempo).toBeGreaterThan(marathon);
        expect(tempo).toBeLessThan(interval);
    });

    it('fills every bar equally when all four paces are identical', () => {
        const { container } = render(
            <PaceTargetsCard
                paces={{
                    easy: 300,
                    marathon: 300,
                    threshold: 300,
                    interval: 300,
                }}
            />,
        );

        expect(fills(container)).toEqual([50, 50, 50, 50]);
    });

    it('names the PR the targets came from, and when it was set', () => {
        render(
            <PaceTargetsCard
                paces={PACES}
                source={{
                    category: 'half_marathon',
                    set_at: '2026-05-19',
                    stale: false,
                    quality_category: null,
                    quality_set_at: null,
                }}
            />,
        );

        expect(
            screen.getByText('from half marathon pr · may 19'),
        ).toBeInTheDocument();
        expect(screen.queryByText('stale')).not.toBeInTheDocument();
    });

    it('marks the source stale when no PR is recent enough to stand behind it', () => {
        render(
            <PaceTargetsCard
                paces={PACES}
                source={{
                    category: '5km',
                    set_at: '2024-01-08',
                    stale: true,
                    quality_category: null,
                    quality_set_at: null,
                }}
            />,
        );

        expect(screen.getByText('from 5 km pr · jan 8')).toBeInTheDocument();
        expect(screen.getByText('stale')).toBeInTheDocument();
    });

    it('names both records when tempo and interval read a fresher one', () => {
        render(
            <PaceTargetsCard
                paces={PACES}
                source={{
                    category: 'half_marathon',
                    set_at: '2026-05-17',
                    stale: false,
                    quality_category: '5km',
                    quality_set_at: '2026-08-29',
                }}
            />,
        );

        expect(
            screen.getByText('tempo + interval from 5 km pr · aug 29'),
        ).toBeInTheDocument();
    });

    it('falls back to the raw category when the PR has no label', () => {
        render(
            <PaceTargetsCard
                paces={PACES}
                source={{
                    category: 'best_90min',
                    set_at: '2026-05-17',
                    stale: false,
                    quality_category: null,
                    quality_set_at: null,
                }}
            />,
        );

        expect(
            screen.getByText('from best_90min pr · may 17'),
        ).toBeInTheDocument();
    });

    it('renders no chips at all when nothing is known about the source', () => {
        render(<PaceTargetsCard paces={PACES} />);

        expect(screen.queryByText(/pr ·/)).not.toBeInTheDocument();
    });
});
