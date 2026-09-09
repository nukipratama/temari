import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import FlagWrong from './FlagWrong';

function renderFlag({
    isDemo = false,
    flagged = false,
    compact = false,
}: { isDemo?: boolean; flagged?: boolean; compact?: boolean } = {}) {
    setMockPage({ auth: { user: makeUser({ is_demo: isDemo }) } });

    return render(
        <FlagWrong
            subjectType="plan_day"
            subjectId={12}
            label="flag this day"
            flagged={flagged}
            compact={compact}
        />,
    );
}

async function openSheet() {
    fireEvent.click(screen.getByRole('button', { name: 'flag this day' }));

    return screen.findByRole('dialog', { name: 'something off?' });
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

    it('loads the sheet only once the icon is tapped', async () => {
        renderFlag();

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        expect(await openSheet()).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'wrong pace' }),
        ).toBeInTheDocument();
    });

    it('posts the chosen reason, then goes inert', async () => {
        renderFlag();
        await openSheet();

        fireEvent.click(screen.getByRole('button', { name: 'too hard' }));
        fireEvent.click(screen.getByRole('button', { name: 'send' }));

        expect(router.post).toHaveBeenCalledWith(
            '/feedback',
            expect.objectContaining({ subject_id: 12, reason: 'too_hard' }),
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => lastPostOptions().onSuccess?.());

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'flag this day' }),
        ).toBeNull();
        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
    });

    it('closes the sheet on never mind without posting', async () => {
        renderFlag();
        await openSheet();

        fireEvent.click(screen.getByText('never mind'));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('collapses its own height on an eyebrow line without shrinking the target', () => {
        renderFlag({ compact: true });

        const control = screen.getByRole('button', { name: 'flag this day' });

        expect(control).toHaveClass('-my-3.5');
        expect(control).toHaveClass('size-11');
    });

    it('renders an inert flagged icon when the server says it is already flagged', () => {
        renderFlag({ flagged: true });

        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });
});
