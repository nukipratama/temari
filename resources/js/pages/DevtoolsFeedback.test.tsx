import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { FeedbackFlagRow } from './DevtoolsFeedback';

import DevtoolsFeedback from './DevtoolsFeedback';

function row(overrides: Partial<FeedbackFlagRow> = {}): FeedbackFlagRow {
    return {
        id: 1,
        created_at: '2h ago',
        created_at_full: 'Wednesday, September 09, 2026 10:00',
        runner: 'Ada',
        subject_label: 'plan day',
        subject_url: '/plan',
        reason: 'wrong day',
        note: null,
        ...overrides,
    };
}

describe('DevtoolsFeedback', () => {
    it('renders the empty state when there are no rows', () => {
        render(<DevtoolsFeedback rows={[]} />);

        expect(screen.getByText('no flags yet')).toBeInTheDocument();
    });

    it('renders a row with a linked subject', () => {
        render(<DevtoolsFeedback rows={[row()]} />);

        expect(screen.getByText('Ada')).toBeInTheDocument();
        expect(screen.getByText('wrong day')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'plan day' })).toHaveAttribute(
            'href',
            '/plan',
        );
    });

    it('draws the subject as plain text when it has no url', () => {
        render(
            <DevtoolsFeedback
                rows={[
                    row({
                        subject_label: 'narration (deleted)',
                        subject_url: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('narration (deleted)')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'narration (deleted)' }),
        ).not.toBeInTheDocument();
    });

    it('shows the full note, wrapped, with the same text as the title', () => {
        const note = 'x'.repeat(80);
        render(<DevtoolsFeedback rows={[row({ note })]} />);

        const cell = screen.getByTitle(note);
        expect(cell.textContent).toBe(note);
    });

    it('shows a dash when there is no note', () => {
        render(<DevtoolsFeedback rows={[row({ note: null })]} />);

        expect(screen.getByText('—')).toBeInTheDocument();
    });
});
