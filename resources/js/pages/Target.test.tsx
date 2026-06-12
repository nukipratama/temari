import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Target from './Target';
import { makeUser, setMockPage } from '@/test/setup';
import type { Rarity } from '@/types/inertia';

function makeGoal(overrides: Partial<Parameters<typeof Target>[0]['goals'][number]> = {}) {
    return {
        id: 'accessory.medal_pertama',
        title: 'Catat PR ke-1',
        description: 'Catat 1 PR di kategori apapun.',
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

describe('Target', () => {
    it('renders the eyebrow with completed / total counts', () => {
        render(<Target goals={[makeGoal()]} completedCount={2} totalCount={28} />);
        expect(screen.getByText(/2 \/ 28 target tercapai/)).toBeInTheDocument();
    });

    it('groups goals into their slot sections and skips empty slots', () => {
        const goals = [
            makeGoal({ id: 'm1', slot: 'medal', title: 'Medali pertama' }),
            makeGoal({ id: 'a1', slot: 'aura', title: 'Aura pemanasan' }),
        ];
        render(<Target goals={goals} completedCount={0} totalCount={2} />);

        expect(screen.getByText('Medali')).toBeInTheDocument();
        expect(screen.getByText('Aura')).toBeInTheDocument();
        expect(screen.getByText('Medali pertama')).toBeInTheDocument();
        expect(screen.getByText('Aura pemanasan')).toBeInTheDocument();
        // Slots with no goals get no section label.
        expect(screen.queryByText('Sepatu')).not.toBeInTheDocument();
    });

    it('marks a completed goal with the check badge', () => {
        const { container } = render(
            <Target goals={[makeGoal({ is_completed: true, current: 1, target: 1 })]} completedCount={1} totalCount={1} />,
        );
        expect(container.querySelector('.bg-horizon')).toBeInTheDocument();
    });

    it('formats fractional current/target values to one decimal', () => {
        const { container } = render(
            <Target
                goals={[makeGoal({ slot: 'sepatu', unit: 'km', current: 12.5, target: 100.5 })]}
                completedCount={0}
                totalCount={1}
            />,
        );
        expect(container.textContent).toContain('12.5');
        expect(container.textContent).toContain('100.5');
    });

    it('renders a zero-width bar when the target is zero', () => {
        const { container } = render(
            <Target goals={[makeGoal({ target: 0, current: 0 })]} completedCount={0} totalCount={1} />,
        );
        const bar = container.querySelector('[style*="width: 0%"]');
        expect(bar).toBeInTheDocument();
    });
});
