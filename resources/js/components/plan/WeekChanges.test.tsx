import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { planDay } from '@/components/catalogue/fixtures';

import WeekChanges from './WeekChanges';

const unchanged = planDay({ date: '2026-06-15', session_type: 'easy' });

const dropped = planDay({
    id: 4,
    date: '2026-06-18',
    session_type: 'easy',
    dropped_from: { session_type: 'tempo', time_trial: false },
    override: {
        kind: 'weekly_drop',
        state: 'locked',
        blocker: 'race week',
        reason: 'one less quality session.',
    },
});

describe('WeekChanges', () => {
    it('shows nothing on a week the plan left as written', () => {
        const { container } = render(
            <WeekChanges days={[unchanged]} onSelect={vi.fn()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('lists each changed day as was → now with the rule that locks it, and opens it', () => {
        const onSelect = vi.fn();
        render(<WeekChanges days={[unchanged, dropped]} onSelect={onSelect} />);

        const row = screen.getByRole('button', {
            name: /thu.*tempo.*easy.*can't bring back.*race week/i,
        });
        expect(screen.getAllByRole('button')).toHaveLength(1);
        fireEvent.click(row);
        expect(onSelect).toHaveBeenCalledExactlyOnceWith('2026-06-18');
    });

    it.each([
        [
            {
                kind: 'weekly_drop',
                state: 'offered',
                label: 'bring back',
                reason: 'r',
            },
            'can bring back',
        ],
        [
            {
                kind: 'weekly_drop',
                state: 'taken',
                note: 'n',
                undoable: true,
            },
            'brought back',
        ],
    ] as const)(
        'says whether a changed day can still come back',
        (override, state) => {
            render(
                <WeekChanges
                    days={[{ ...dropped, override }]}
                    onSelect={vi.fn()}
                />,
            );

            expect(screen.getByRole('button')).toHaveTextContent(state);
        },
    );
});
