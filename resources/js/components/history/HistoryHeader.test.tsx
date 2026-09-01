import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HistoryHeader from './HistoryHeader';

describe('HistoryHeader', () => {
    it('draws the two-line headline both views share', () => {
        render(<HistoryHeader active="feed" />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'every runhas a story.',
        );
    });

    it('names the lifetime activity count in the eyebrow', () => {
        render(<HistoryHeader active="feed" activityCount={42} />);

        expect(screen.getByText('History · 42 activities')).toBeInTheDocument();
    });

    it('falls back to a bare label while the count is unknown', () => {
        render(<HistoryHeader active="calendar" />);

        expect(screen.getByText('History')).toBeInTheDocument();
    });

    it('lights the tab it was given', () => {
        render(<HistoryHeader active="calendar" />);

        expect(screen.getByText('calendar').closest('a')).toHaveClass(
            'bg-card',
        );
        expect(screen.getByText('feed').closest('a')).not.toHaveClass(
            'bg-card',
        );
    });
});
