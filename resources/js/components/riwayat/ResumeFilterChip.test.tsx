import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ResumeFilterChip from './ResumeFilterChip';

describe('ResumeFilterChip', () => {
    it('names what resuming would apply', () => {
        render(
            <ResumeFilterChip
                summary="Half ke atas · Nyala"
                onResume={vi.fn()}
                onDismiss={vi.fn()}
            />,
        );

        expect(
            screen.getByText(/Lanjutkan: Half ke atas · Nyala/),
        ).toBeInTheDocument();
    });

    it('never resumes on its own, only when tapped', () => {
        const onResume = vi.fn();
        render(
            <ResumeFilterChip
                summary="Nyala"
                onResume={onResume}
                onDismiss={vi.fn()}
            />,
        );

        expect(onResume).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText(/Lanjutkan:/));
        expect(onResume).toHaveBeenCalledOnce();
    });

    it('can be dismissed so it cannot nag', () => {
        const onDismiss = vi.fn();
        render(
            <ResumeFilterChip
                summary="Nyala"
                onResume={vi.fn()}
                onDismiss={onDismiss}
            />,
        );

        fireEvent.click(screen.getByLabelText('Lupakan filter terakhir'));

        expect(onDismiss).toHaveBeenCalledOnce();
    });
});
