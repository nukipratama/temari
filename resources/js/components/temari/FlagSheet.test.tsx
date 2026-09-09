import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FlagSheet from './FlagSheet';

function renderSheet({
    subjectType = 'plan_day' as const,
    onOpenChange = vi.fn(),
    onSent = vi.fn(),
}: {
    subjectType?: 'plan_day' | 'narration';
    onOpenChange?: (open: boolean) => void;
    onSent?: () => void;
} = {}) {
    render(
        <FlagSheet
            subjectType={subjectType}
            subjectId={12}
            open
            onOpenChange={onOpenChange}
            onSent={onSent}
        />,
    );

    return { onOpenChange, onSent };
}

function lastPostOptions() {
    const call = vi.mocked(router.post).mock.calls[0];

    return call[2] as { onSuccess?: () => void };
}

describe('FlagSheet', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockReset();
    });

    it('offers the plan day reasons', () => {
        renderSheet();

        expect(
            screen.getByRole('dialog', { name: 'something off?' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'wrong pace' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'tone off' })).toBeNull();
    });

    it('offers the narration reasons for a narration subject', () => {
        renderSheet({ subjectType: 'narration' });

        expect(
            screen.getByRole('button', { name: 'tone off' }),
        ).toBeInTheDocument();
    });

    it('keeps send disabled until a reason is chosen', () => {
        renderSheet();

        expect(screen.getByRole('button', { name: 'send' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'too hard' }));

        expect(screen.getByRole('button', { name: 'send' })).toBeEnabled();
    });

    it('posts the reason and the trimmed note, then reports it sent', () => {
        const { onOpenChange, onSent } = renderSheet();

        fireEvent.click(screen.getByRole('button', { name: 'too hard' }));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '  too long for a tuesday  ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'send' }));

        expect(router.post).toHaveBeenCalledWith(
            '/feedback',
            {
                subject_type: 'plan_day',
                subject_id: 12,
                reason: 'too_hard',
                note: 'too long for a tuesday',
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => lastPostOptions().onSuccess?.());

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(onSent).toHaveBeenCalled();
    });

    it('closes on never mind without posting', () => {
        const { onOpenChange } = renderSheet();

        fireEvent.click(screen.getByText('never mind'));

        expect(vi.mocked(onOpenChange).mock.calls[0]?.[0]).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('caps the note at the column length', () => {
        renderSheet();

        expect(screen.getByRole('textbox')).toHaveAttribute('maxlength', '280');
    });
});
