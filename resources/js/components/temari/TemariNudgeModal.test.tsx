import { act, cleanup, render, screen } from '@testing-library/react';
import { Check } from 'lucide-react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import TemariNudgeModal from './TemariNudgeModal';

const props = {
    onClose: vi.fn(),
    title: 'Nudge title',
    body: 'A friendly message.',
    primaryLabel: 'Do it',
    primaryIcon: Check,
    onPrimary: vi.fn(),
};

afterEach(async () => {
    cleanup();
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
});

describe('TemariNudgeModal', () => {
    it('loads nothing until it is first opened', () => {
        const { container } = render(
            <TemariNudgeModal open={false} {...props} />,
        );

        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('loads the dialog on its first open and closes it again', async () => {
        const { rerender } = render(
            <TemariNudgeModal open={false} {...props} />,
        );

        rerender(<TemariNudgeModal open {...props} />);
        expect(
            await screen.findByRole('dialog', { name: 'Nudge title' }),
        ).toBeInTheDocument();

        rerender(<TemariNudgeModal open={false} {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
