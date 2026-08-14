import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ResumeFilterChip from './ResumeFilterChip';

describe('ResumeFilterChip', () => {
    it('names what resuming would apply', () => {
        render(
            <ResumeFilterChip
                summary="Half marathon+ · Blazing"
                onResume={vi.fn()}
                onDismiss={vi.fn()}
            />,
        );

        expect(
            screen.getByText(/Resume: Half marathon\+ · Blazing/),
        ).toBeInTheDocument();
    });

    it('never resumes on its own, only when tapped', () => {
        const onResume = vi.fn();
        render(
            <ResumeFilterChip
                summary="Blazing"
                onResume={onResume}
                onDismiss={vi.fn()}
            />,
        );

        expect(onResume).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText(/Resume:/));
        expect(onResume).toHaveBeenCalledOnce();
    });

    it('can be dismissed so it cannot nag', () => {
        const onDismiss = vi.fn();
        render(
            <ResumeFilterChip
                summary="Blazing"
                onResume={vi.fn()}
                onDismiss={onDismiss}
            />,
        );

        fireEvent.click(screen.getByLabelText('Forget last filter'));

        expect(onDismiss).toHaveBeenCalledOnce();
    });
});
