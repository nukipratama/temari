import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Rarity } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import Goals from './Goals';

function makeGoal(
    overrides: Partial<Parameters<typeof Goals>[0]['goals'][number]> = {},
) {
    return {
        id: 'accessory.medal_pertama',
        title: 'Log your 1st PR',
        description: 'Log 1 PR in any category.',
        slot: 'medal',
        rarity: 'biasa' as Rarity,
        current: 0,
        target: 1,
        unit: 'PR',
        is_completed: false,
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Goals', () => {
    it('renders the eyebrow with completed / total counts', () => {
        render(
            <Goals goals={[makeGoal()]} completedCount={2} totalCount={28} />,
        );
        expect(screen.getByText(/2 \/ 28 goals reached/)).toBeInTheDocument();
    });

    it('groups goals into their slot sections and skips empty slots', () => {
        const goals = [
            makeGoal({ id: 'm1', slot: 'medal', title: 'First medal' }),
            makeGoal({ id: 'a1', slot: 'aura', title: 'Warmup aura' }),
        ];
        render(<Goals goals={goals} completedCount={0} totalCount={2} />);

        expect(screen.getByText('Medal')).toBeInTheDocument();
        expect(screen.getByText('Aura')).toBeInTheDocument();
        expect(screen.getByText('First medal')).toBeInTheDocument();
        expect(screen.getByText('Warmup aura')).toBeInTheDocument();
        // Slots with no goals get no section label.
        expect(screen.queryByText('Shoes')).not.toBeInTheDocument();
    });

    it('marks a completed goal with the check badge', () => {
        const { container } = render(
            <Goals
                goals={[
                    makeGoal({ is_completed: true, current: 1, target: 1 }),
                ]}
                completedCount={1}
                totalCount={1}
            />,
        );
        expect(container.querySelector('.bg-horizon')).toBeInTheDocument();
    });

    it('formats fractional current/target values to one decimal', () => {
        const { container } = render(
            <Goals
                goals={[
                    makeGoal({
                        slot: 'sepatu',
                        unit: 'km',
                        current: 12.5,
                        target: 100.5,
                    }),
                ]}
                completedCount={0}
                totalCount={1}
            />,
        );
        expect(container.textContent).toContain('12.5');
        expect(container.textContent).toContain('100.5');
    });

    it('renders a zero-width bar when the target is zero', () => {
        const { container } = render(
            <Goals
                goals={[makeGoal({ target: 0, current: 0 })]}
                completedCount={0}
                totalCount={1}
            />,
        );
        const bar = container.querySelector('[style*="width: 0%"]');
        expect(bar).toBeInTheDocument();
    });

    it('shows the "Almost!" nudge once progress reaches 75% but isn\'t completed yet', () => {
        render(
            <Goals
                goals={[
                    makeGoal({ current: 80, target: 100, is_completed: false }),
                ]}
                completedCount={0}
                totalCount={1}
            />,
        );
        expect(screen.getByText('Almost!')).toBeInTheDocument();
    });

    it('hides the "Almost!" nudge below the 75% threshold', () => {
        render(
            <Goals
                goals={[
                    makeGoal({ current: 50, target: 100, is_completed: false }),
                ]}
                completedCount={0}
                totalCount={1}
            />,
        );
        expect(screen.queryByText('Almost!')).not.toBeInTheDocument();
    });

    it('gives each progress bar a meaningful accessible name', () => {
        render(
            <Goals
                goals={[
                    makeGoal({
                        title: 'Log your 1st PR',
                        current: 0,
                        target: 1,
                        unit: 'PR',
                    }),
                ]}
                completedCount={0}
                totalCount={1}
            />,
        );
        expect(
            screen.getByRole('progressbar', {
                name: 'Log your 1st PR: 0/1 PR',
            }),
        ).toBeInTheDocument();
    });
});
