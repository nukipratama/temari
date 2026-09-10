import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AthleteRow } from '@/pages/Narration/types';

import { formMock } from '@/test/setup';

import AthleteTable from './AthleteTable';

const ROW: AthleteRow = {
    user_id: 7,
    user_name: 'Nuki',
    is_demo: false,
    deleted: false,
    today: 0.25,
    last7: 1.5,
    last30: 4.75,
    calls: 42,
    ceiling: 1,
    ceiling_overridden: false,
    capped: false,
    sparkline: [
        { day: '2026-09-09', cost: 0.1 },
        { day: '2026-09-10', cost: 0.25 },
    ],
    served: { llm: 8, rule_based: 2, unknown: 0 },
    flags: 0,
    dead_lettered: 0,
};

function renderTable(overrides: Partial<AthleteRow> = {}) {
    return render(
        <AthleteTable rows={[{ ...ROW, ...overrides }]} currency="USD" />,
    );
}

describe('AthleteTable', () => {
    it('links the athlete to their own page', () => {
        renderTable();

        expect(screen.getByRole('link', { name: 'Nuki' })).toHaveAttribute(
            'href',
            '/devtools/narration/athletes/7',
        );
    });

    it('shows the three money windows', () => {
        renderTable();

        expect(screen.getByText('$1.50')).toBeInTheDocument();
        expect(screen.getByText('$4.75')).toBeInTheDocument();
    });

    it('reads today against the athlete own ceiling', () => {
        renderTable();

        expect(screen.getByText(/\$0\.25 \/ \$1\.00/)).toBeInTheDocument();
    });

    it('marks a ceiling that an operator overrode for today', () => {
        renderTable({ ceiling: 3, ceiling_overridden: true });

        expect(screen.getByText('override')).toBeInTheDocument();
    });

    it('says when an athlete has no ceiling at all', () => {
        renderTable({ ceiling: null });

        expect(screen.getByText('no ceiling')).toBeInTheDocument();
    });

    it('flags a capped athlete', () => {
        renderTable({ capped: true });

        expect(screen.getByText('capped')).toBeInTheDocument();
    });

    it('labels the demo account', () => {
        renderTable({ is_demo: true });

        expect(screen.getByText('demo')).toBeInTheDocument();
    });

    it('falls back to the bare id for a deleted account', () => {
        renderTable({ user_name: null, deleted: true });

        expect(
            screen.getByRole('link', { name: 'User #7' }),
        ).toBeInTheDocument();
        expect(screen.getByText('deleted')).toBeInTheDocument();
    });

    it('splits the LLM share from rule-based and unknown', () => {
        renderTable();

        expect(screen.getByText('80% llm')).toBeInTheDocument();
        expect(
            screen.getByText('8 llm · 2 rule · 0 unknown'),
        ).toBeInTheDocument();
    });

    it('shows a dash rather than 0% when nothing was narrated in range', () => {
        renderTable({ served: { llm: 0, rule_based: 0, unknown: 0 } });

        expect(screen.queryByText(/% llm/)).not.toBeInTheDocument();
    });

    it('hides the retry button when nothing is dead-lettered', () => {
        renderTable();

        expect(
            screen.queryByRole('button', { name: 'retry failed' }),
        ).not.toBeInTheDocument();
    });

    it('posts the per-athlete retry beside the dead-letter count', () => {
        renderTable({ dead_lettered: 3 });

        expect(screen.getByText('3')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'retry failed' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/retry-failed',
            expect.anything(),
        );
    });

    it('falls back to an empty state with no athletes at all', () => {
        render(<AthleteTable rows={[]} currency="USD" />);

        expect(screen.queryByText('Nuki')).not.toBeInTheDocument();
    });
});
