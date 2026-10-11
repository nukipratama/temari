import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanRaceCard as PlanRaceCardPayload } from '@/types/inertia';

import { RACE_DEMO_BLOCKED } from '@/lib/raceGoal';
import { makeUser, setMockPage } from '@/test/setup';

import PlanRaceCard from './PlanRaceCard';

const TODAY = '2026-10-11';

function card(
    overrides: Partial<PlanRaceCardPayload> = {},
): PlanRaceCardPayload {
    return {
        state: 'ahead',
        race_id: 7,
        race_date: '2026-12-06',
        distance_m: 10_000,
        goal_time_sec: 3_600,
        name: 'City 10K',
        days_to_race: 56,
        finish_time_sec: null,
        ...overrides,
    };
}

const CANDIDATE = {
    activity_id: 99,
    name: 'Morning Run',
    distance_m: 10_080,
    elapsed_time_sec: 3_521,
    started_at: '2026-12-06 06:30:00',
};

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

describe('PlanRaceCard', () => {
    it('renders nothing without a race, or for a result the season card states', () => {
        const { container, rerender } = render(
            <PlanRaceCard card={null} today={TODAY} />,
        );
        expect(container).toBeEmptyDOMElement();

        rerender(
            <PlanRaceCard
                card={card({
                    state: 'result_recorded',
                    days_to_race: null,
                    finish_time_sec: 3_521,
                })}
                today={TODAY}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('counts a race ahead down in weeks, links to the race, and adds the supported time once it loads', () => {
        const { rerender } = render(
            <PlanRaceCard card={card()} today={TODAY} />,
        );

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', '/race');
        expect(link).toHaveTextContent('race · dec 6 · in 8 weeks');
        expect(link).toHaveTextContent('10K 1:00:00');
        expect(link).not.toHaveTextContent('supported');

        rerender(
            <PlanRaceCard
                card={card()}
                today={TODAY}
                supportedTimeSec={3_730}
            />,
        );
        expect(screen.getByRole('link')).toHaveTextContent(
            '10K 1:00:00 vs supported 1:02:10',
        );
    });

    it('counts a race under two weeks out in days', () => {
        render(
            <PlanRaceCard
                card={card({ race_date: '2026-10-21', days_to_race: 10 })}
                today={TODAY}
                supportedTimeSec={null}
            />,
        );

        expect(screen.getByRole('link')).toHaveTextContent(
            'race · oct 21 · in 10 days',
        );
        expect(screen.getByRole('link')).not.toHaveTextContent('supported');
    });

    it('marks race week in citrus with the goal pace, naming the weekday inside six days', () => {
        render(
            <PlanRaceCard
                card={card({
                    state: 'week',
                    race_date: '2026-10-17',
                    days_to_race: 6,
                    goal_time_sec: 3_600,
                })}
                today={TODAY}
            />,
        );

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', '/race');
        expect(link).toHaveClass('border-citrus');
        expect(link).toHaveTextContent('race saturday');
        expect(link).toHaveTextContent(
            '10K 1:00:00 · pace 6:00 /km · stay fresh',
        );
    });

    it('says seven days at the edge of race week', () => {
        render(
            <PlanRaceCard
                card={card({
                    state: 'week',
                    race_date: '2026-10-18',
                    days_to_race: 7,
                })}
                today={TODAY}
            />,
        );

        expect(screen.getByRole('link')).toHaveTextContent('race in 7 days');
    });

    it('turns into a lime hero on race day with dark text', () => {
        render(
            <PlanRaceCard
                card={card({
                    state: 'day',
                    race_date: TODAY,
                    days_to_race: 0,
                    goal_time_sec: 3_000,
                })}
                today={TODAY}
            />,
        );

        const hero = screen.getByRole('region', { name: 'race day' });
        expect(hero).toHaveClass('bg-horizon');
        expect(hero).toHaveTextContent('race day · 10K');
        expect(screen.getByText('go get your 50:00.')).toHaveClass('text-sky');
        expect(
            screen.getByText('5:00 /km · start easy, finish strong'),
        ).toHaveClass('text-sky-2');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('holds the result question on a skeleton until the candidate run loads', () => {
        const { container } = render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
            />,
        );

        expect(
            screen.getByText(/dec 6 · is this your race\?/),
        ).toBeInTheDocument();
        expect(container.querySelector('.skeleton')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('confirms the matched run through the outcome endpoint', () => {
        render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
                candidate={CANDIDATE}
            />,
        );

        expect(
            screen.getByText('Morning Run · 10.1 km · 58:41'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'pick another' }),
        ).toHaveAttribute('href', '/race');

        fireEvent.click(screen.getByRole('button', { name: 'yes' }));

        expect(lastPostCall()?.[0]).toBe('/race/7/outcome');
        expect(lastPostCall()?.[1]).toEqual({
            outcome: 'confirmed',
            activity_id: 99,
        });
    });

    it('records a race not run through the same endpoint', () => {
        render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
                candidate={CANDIDATE}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: "didn't run" }));

        expect(lastPostCall()?.[0]).toBe('/race/7/outcome');
        expect(lastPostCall()?.[1]).toEqual({ outcome: 'did_not_run' });
    });

    it('asks how it went when no run matches, offering a pick or not run', () => {
        render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
                candidate={null}
            />,
        );

        expect(screen.getByText('how did it go?')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'yes' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'pick a run' }),
        ).toHaveAttribute('href', '/race');
        expect(
            screen.getByRole('button', { name: "didn't run" }),
        ).toBeInTheDocument();
    });

    it('disables the answers while a post is in flight', () => {
        render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
                candidate={CANDIDATE}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'yes' }));
        act(() => {
            lastPostCall()?.[2]?.onStart?.({} as never);
        });

        expect(screen.getByRole('button', { name: 'yes' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: "didn't run" }),
        ).toBeDisabled();

        act(() => {
            lastPostCall()?.[2]?.onFinish?.({} as never);
        });
        expect(screen.getByRole('button', { name: 'yes' })).toBeEnabled();
    });

    it('opens the demo-blocked modal instead of posting for the demo athlete', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        vi.mocked(router.post).mockClear();

        render(
            <PlanRaceCard
                card={card({ state: 'result_pending', days_to_race: null })}
                today={TODAY}
                candidate={CANDIDATE}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'yes' }));

        expect(router.post).not.toHaveBeenCalled();
        expect(
            await screen.findByText(RACE_DEMO_BLOCKED.title),
        ).toBeInTheDocument();
    });
});
