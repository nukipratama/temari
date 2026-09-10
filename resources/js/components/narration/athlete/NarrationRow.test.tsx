import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    NarrationRow as Row,
    ReplayBudget,
} from '@/pages/Narration/types';

import NarrationRow from './NarrationRow';

function row(overrides: Partial<Row> = {}): Row {
    return {
        id: 12,
        kind: 'trend_read',
        discriminator: '2026-09-10',
        status: 'done',
        served_by: 'llm',
        origin: 'scheduled',
        cost: 0.02,
        last_cost: 0.01,
        prompt_tokens: 1200,
        completion_tokens: 300,
        latency_ms: 850,
        steps: 2,
        tool_calls: [
            {
                tool: 'recent_runs',
                arguments_summary: 'limit=5',
                duration_ms: 42,
            },
        ],
        content: 'you held the pace.',
        error: null,
        generated_at: '2026-09-10T08:00:00Z',
        flag: null,
        version_count: 0,
        previous_content: null,
        ...overrides,
    };
}

const budget: ReplayBudget = {
    cap: 0.5,
    spent_today: 0.1,
    cap_reached: false,
};

function renderRow(overrides: Partial<Row> = {}, replayBudget = budget) {
    return render(
        <NarrationRow
            row={row(overrides)}
            currency="USD"
            athleteId={7}
            replayBudget={replayBudget}
        />,
    );
}

describe('NarrationRow', () => {
    it('shows the kind, status, origin, producer, cost, tokens and tool trace', () => {
        renderRow();

        expect(screen.getByText('trend_read')).toBeInTheDocument();
        expect(screen.getByText('done')).toBeInTheDocument();
        expect(screen.getByText('scheduled')).toBeInTheDocument();
        expect(screen.getByText('llm')).toBeInTheDocument();
        expect(screen.getByText('$0.02')).toBeInTheDocument();
        expect(screen.getByText('1,200 in / 300 out')).toBeInTheDocument();
        expect(screen.getByText('850 ms')).toBeInTheDocument();
        expect(
            screen.getByText(/recent_runs · limit=5 · 42ms/),
        ).toBeInTheDocument();
        expect(screen.getByText('you held the pace.')).toBeInTheDocument();
    });

    it('reads a missing producer as unknown rather than rule-based', () => {
        renderRow({ served_by: null, origin: null, latency_ms: null });

        expect(screen.getByText('unknown')).toBeInTheDocument();
        expect(screen.getByText('unattributed')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('collapses long prose until it is expanded', () => {
        const long = 'word '.repeat(80).trim();
        renderRow({ content: long });

        expect(screen.getByText(/…$/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'show all' }));

        expect(screen.getByText(long)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'show less' }));
        expect(screen.getByText(/…$/)).toBeInTheDocument();
    });

    it('reveals the diff against the previous version on demand', () => {
        renderRow({
            version_count: 2,
            previous_content: 'you dropped the pace.',
        });

        expect(
            screen.queryByText('previous vs current'),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /2 earlier version\(s\)/ }),
        );

        expect(screen.getByText('previous vs current')).toBeInTheDocument();
    });

    it('offers a replay only on a flagged row, quoting its last cost', () => {
        renderRow();
        expect(
            screen.queryByRole('button', { name: /replay/i }),
        ).not.toBeInTheDocument();

        renderRow({
            flag: { reason: 'facts_wrong', note: 'never happened', at: null },
        });

        expect(screen.getByText(/flagged: facts_wrong/)).toBeInTheDocument();
        expect(screen.getByText(/never happened/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /replay/i }));
        expect(
            screen.getByText(
                /it last cost \$0.01; today's replays are at \$0.10 of \$0.50/,
            ),
        ).toBeInTheDocument();
    });

    it('refuses the replay in place once the cap is reached', () => {
        renderRow(
            { flag: { reason: null, note: null, at: null } },
            { cap: 0.5, spent_today: 0.5, cap_reached: true },
        );

        expect(
            screen.queryByRole('button', { name: /replay/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/replay budget spent for today/),
        ).toBeInTheDocument();
    });

    it('shows the error a failed block carries', () => {
        renderRow({ status: 'failed', content: null, error: 'Azure down' });

        expect(screen.getByText('Azure down')).toBeInTheDocument();
    });
});
