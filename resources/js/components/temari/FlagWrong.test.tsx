import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import FlagWrong from './FlagWrong';

function renderFlag(isDemo = false) {
    setMockPage({ auth: { user: makeUser({ is_demo: isDemo }) } });

    return render(
        <FlagWrong
            subjectType="plan_day"
            subjectId={12}
            label="flag this day"
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

    it('starts closed, showing only the flag control', () => {
        renderFlag();

        expect(
            screen.getByRole('button', { name: /flag this day/ }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('renders nothing for the demo account', () => {
        const { container } = renderFlag(true);

        expect(container).toBeEmptyDOMElement();
    });

    it('opens a note field and closes again on never mind', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: /flag this day/ }));

        expect(screen.getByRole('textbox')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'never mind' }));

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('posts the subject and the trimmed note, then confirms quietly', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: /flag this day/ }));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '  too long for a tuesday  ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'send' }));

        expect(router.post).toHaveBeenCalledWith(
            '/feedback',
            {
                subject_type: 'plan_day',
                subject_id: 12,
                note: 'too long for a tuesday',
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => lastPostOptions().onSuccess?.());

        expect(screen.getByText('noted, thanks')).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('caps the note at the column length', () => {
        renderFlag();
        fireEvent.click(screen.getByRole('button', { name: /flag this day/ }));

        expect(screen.getByRole('textbox')).toHaveAttribute('maxlength', '280');
    });
});
