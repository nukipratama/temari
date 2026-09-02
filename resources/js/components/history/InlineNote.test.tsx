import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import InlineNote, { RangeWidenedNote, WeekFocusNote } from './InlineNote';

describe('InlineNote', () => {
    it('renders the icon and the sentence', () => {
        const { container } = render(
            <InlineNote icon="mdi:history">Something is hidden.</InlineNote>,
        );

        expect(screen.getByText('Something is hidden.')).toBeInTheDocument();
        expect(
            container.querySelector('[data-icon="mdi:history"]'),
        ).not.toBeNull();
    });

    it('renders no trailing control when the note offers no way out', () => {
        render(<InlineNote icon="mdi:history">Just one line.</InlineNote>);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders the action beside the sentence when one is given', () => {
        render(
            <InlineNote
                icon="mdi:history"
                action={<a href="/activities">Exit</a>}
            >
                Just one line.
            </InlineNote>,
        );

        expect(screen.getByRole('link', { name: 'Exit' })).toBeInTheDocument();
    });
});

describe('RangeWidenedNote', () => {
    it('names the range the server widened to', () => {
        render(<RangeWidenedNote rangeFilter="1y" />);

        expect(
            screen.getByText(/Range automatically widened to full year/),
        ).toBeInTheDocument();
    });

    it('names no range at all when widened to the full history', () => {
        render(<RangeWidenedNote rangeFilter="all" />);

        expect(screen.getByText(/showing all your runs/)).toBeInTheDocument();
    });
});

describe('WeekFocusNote', () => {
    // Reached from the weekly-recap notification. Without the note the view
    // looks like a history that mysteriously lost most of its runs.
    it('names the Monday-to-Sunday window and offers a way back to the full list', () => {
        render(<WeekFocusNote weekEnding="2026-05-17" />);

        expect(
            screen.getByText(
                /Viewing the week of monday, may 11 - sunday, may 17/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /View all runs/ }),
        ).toHaveAttribute('href', '/history');
    });
});
