import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { GoalsSummary } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import GoalsCard from './GoalsCard';

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('GoalsCard', () => {
    it('returns null when there is no goalsSummary', () => {
        const { container } = render(<GoalsCard />);
        expect(container.firstChild).toBeNull();
    });

    it('returns null when goalsSummary has no closest goals', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary: { total: 0, completed: 0, closest: [] },
        });
        const { container } = render(<GoalsCard />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the closest goals with current/target and unit', () => {
        const goalsSummary: GoalsSummary = {
            total: 3,
            completed: 1,
            closest: [
                {
                    id: 'g1',
                    title: 'Run 100 KM this month',
                    current: 100,
                    target: 100,
                    unit: 'km',
                },
                {
                    id: 'g2',
                    title: 'Half marathon',
                    current: 12.5,
                    target: 21.1,
                    unit: 'km',
                },
                {
                    id: 'g3',
                    title: 'Empty target',
                    current: 0,
                    target: 0,
                    unit: 'sessions',
                },
            ],
        };
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary,
        });
        render(<GoalsCard />);
        expect(screen.getByText('Closest targets')).toBeInTheDocument();
        expect(screen.getByText('Run 100 KM this month')).toBeInTheDocument();
        expect(screen.getByText('Half marathon')).toBeInTheDocument();
        expect(
            screen.getByText((_, el) => el?.textContent === '100/100'),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, el) => el?.textContent === '12.5/21.1'),
        ).toBeInTheDocument();
        // target 0 -> "0/0", no divide-by-zero NaN.
        expect(
            screen.getByText((_, el) => el?.textContent === '0/0'),
        ).toBeInTheDocument();
    });

    it('gives each progress bar a meaningful accessible name', () => {
        const goalsSummary: GoalsSummary = {
            total: 1,
            completed: 0,
            closest: [
                {
                    id: 'g1',
                    title: 'Run 100 KM this month',
                    current: 40,
                    target: 100,
                    unit: 'km',
                },
            ],
        };
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary,
        });
        render(<GoalsCard />);
        expect(
            screen.getByRole('progressbar', {
                name: 'Run 100 KM this month: 40/100 km',
            }),
        ).toBeInTheDocument();
    });
});
