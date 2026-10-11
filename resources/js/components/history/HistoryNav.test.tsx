import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import * as pendingTab from '@/lib/pendingTab';
import { setMockPage } from '@/test/setup';

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

    it('arms the history tab from the page it is on when the other half is tapped', () => {
        const arm = vi.spyOn(pendingTab, 'armPendingTab');
        setMockPage({}, '/history', 'History');
        render(<HistoryNav active="feed" />);

        fireEvent.click(screen.getByText('calendar'));

        expect(arm).toHaveBeenCalledExactlyOnceWith('history', 'History');
        arm.mockRestore();
    });

    it('arms nothing when the active half is tapped', () => {
        const arm = vi.spyOn(pendingTab, 'armPendingTab');
        render(<HistoryNav active="feed" />);

        fireEvent.click(screen.getByText('feed'));

        expect(arm).not.toHaveBeenCalled();
        arm.mockRestore();
    });
});
