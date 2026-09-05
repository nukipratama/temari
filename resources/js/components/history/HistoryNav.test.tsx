import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HistoryNav from './HistoryNav';

describe('HistoryNav', () => {
    it('links each tab to its real route', () => {
        render(<HistoryNav active="feed" />);

        expect(screen.getByText('feed').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );
        expect(screen.getByText('calendar').closest('a')).toHaveAttribute(
            'href',
            '/history?view=calendar',
        );
    });

    it('highlights only the active tab', () => {
        render(<HistoryNav active="calendar" />);

        expect(screen.getByText('calendar').closest('a')).toHaveClass(
            'bg-card',
        );
        expect(screen.getByText('feed').closest('a')).not.toHaveClass(
            'bg-card',
        );
    });
});
