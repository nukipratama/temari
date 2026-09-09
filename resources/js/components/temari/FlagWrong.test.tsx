import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import FlagWrong from './FlagWrong';

function renderFlag({
    isDemo = false,
    flagged = false,
}: { isDemo?: boolean; flagged?: boolean } = {}) {
    setMockPage({ auth: { user: makeUser({ is_demo: isDemo }) } });

    return render(
        <FlagWrong
            subjectType="plan_day"
            subjectId={12}
            label="flag this day"
            flagged={flagged}
        />,
    );
}

function lastPostOptions() {
    const call = vi.mocked(router.post).mock.calls[0];

    return call[2] as { onSuccess?: () => void };
}

describe('FlagWrong', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockReset();
    });

    it('shows one icon-only control and no sheet', () => {
        renderFlag();

        const control = screen.getByRole('button', { name: 'flag this day' });

        expect(control).toHaveAttribute('title', 'flag this day');
        expect(control).toHaveTextContent('');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('renders nothing for the demo account', () => {
        const { container } = renderFlag({ isDemo: true });

        expect(container).toBeEmptyDOMElement();
    });

    it('offers the plan day reasons in a sheet', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));

        expect(
            screen.getByRole('dialog', { name: 'something off?' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'wrong pace' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'tone off' })).toBeNull();
    });

    it('offers the narration reasons for a narration subject', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(
            <FlagWrong
                subjectType="narration"
                subjectId={4}
                label="flag this read"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'flag this read' }));

        expect(
            screen.getByRole('button', { name: 'tone off' }),
        ).toBeInTheDocument();
    });

    it('keeps send disabled until a reason is chosen', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));

        expect(screen.getByRole('button', { name: 'send' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'too hard' }));

        expect(screen.getByRole('button', { name: 'send' })).toBeEnabled();
    });

    it('posts the reason and the trimmed note, then goes inert', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));
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

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'flag this day' }),
        ).toBeNull();
        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
    });

    it('closes the sheet on never mind without posting', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));
        fireEvent.click(screen.getByText('never mind'));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('renders an inert flagged icon when the server says it is already flagged', () => {
        renderFlag({ flagged: true });

        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('caps the note at the column length', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));

        expect(screen.getByRole('textbox')).toHaveAttribute('maxlength', '280');
    });
});
