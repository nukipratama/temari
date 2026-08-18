import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TodayHistoryTabs from './TodayHistoryTabs';

describe('TodayHistoryTabs', () => {
    it('renders both tabs linking to their pages', () => {
        render(<TodayHistoryTabs active="today" />);
        expect(screen.getByText('Today').closest('a')).toHaveAttribute(
            'href',
            '/',
        );
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );
    });

    it('marks the active tab with aria-current', () => {
        render(<TodayHistoryTabs active="history" />);
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
