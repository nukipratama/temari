import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HistoryTabs from './HistoryTabs';

describe('HistoryTabs', () => {
    it('renders both sub-tab labels linking to their pages', () => {
        render(<HistoryTabs active="feed" />);
        expect(screen.getByText('Feed').closest('a')).toHaveAttribute(
            'href',
            '/activities',
        );
        expect(screen.getByText('Calendar').closest('a')).toHaveAttribute(
            'href',
            '/calendar',
        );
    });

    it('marks the active tab with aria-current', () => {
        render(<HistoryTabs active="calendar" />);
        expect(screen.getByText('Calendar').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Feed').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
