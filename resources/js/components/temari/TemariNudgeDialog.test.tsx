import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { Check } from 'lucide-react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { settle } from '@/test/overlayHistory';

import TemariNudgeDialog from './TemariNudgeDialog';

const baseProps = {
    title: 'Nudge title',
    body: 'A friendly message.',
    primaryLabel: 'Do it',
    primaryIcon: Check,
    onPrimary: vi.fn(),
};

afterEach(async () => {
    cleanup();
    await settle();
});

describe('TemariNudgeDialog', () => {
    it('renders nothing when closed', () => {
        const { container } = render(
            <TemariNudgeDialog open={false} onClose={vi.fn()} {...baseProps} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, primary CTA, and default dismiss label', () => {
        render(<TemariNudgeDialog open onClose={vi.fn()} {...baseProps} />);
        expect(screen.getByText('Nudge title')).toBeInTheDocument();
        expect(screen.getByText('A friendly message.')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Do it' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'not now' }),
        ).toBeInTheDocument();
    });

    it('takes the pose its caller asks for, neutral by default', () => {
        const { rerender } = render(
            <TemariNudgeDialog open onClose={vi.fn()} {...baseProps} />,
        );
        expect(document.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'neutral',
        );

        rerender(
            <TemariNudgeDialog
                open
                onClose={vi.fn()}
                {...baseProps}
                pose="concerned"
            />,
        );
        expect(document.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'concerned',
        );
    });

    it('draws Temari in with the one-shot trace', () => {
        render(<TemariNudgeDialog open onClose={vi.fn()} {...baseProps} />);
        const mascot = document.querySelector('svg[data-mascot]');

        expect(mascot).toHaveAttribute('width', '72');
        expect(mascot?.querySelector('.draw-in')).not.toBeNull();
    });

    it('wires the dialog to the title via aria-labelledby', () => {
        render(<TemariNudgeDialog open onClose={vi.fn()} {...baseProps} />);
        const dialog = screen.getByRole('dialog', { name: 'Nudge title' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
    });

    it('honors a custom secondary label', () => {
        render(
            <TemariNudgeDialog
                open
                onClose={vi.fn()}
                {...baseProps}
                secondaryLabel="Cancel"
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Cancel' }),
        ).toBeInTheDocument();
    });

    it('calls onPrimary when the primary CTA is clicked', () => {
        const onPrimary = vi.fn();
        render(
            <TemariNudgeDialog
                open
                onClose={vi.fn()}
                {...baseProps}
                onPrimary={onPrimary}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Do it' }));
        expect(onPrimary).toHaveBeenCalledOnce();
    });

    it('calls onClose from both the dismiss CTA and the top-left close button', () => {
        const onClose = vi.fn();
        render(<TemariNudgeDialog open onClose={onClose} {...baseProps} />);
        fireEvent.click(screen.getByRole('button', { name: 'not now' }));
        fireEvent.click(screen.getByLabelText('Close'));
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('closes on Back without leaving the page', async () => {
        window.history.pushState({ page: 'settings' }, '');
        const onClose = vi.fn();
        const pageSawBack = vi.fn();
        window.addEventListener('popstate', pageSawBack);
        render(<TemariNudgeDialog open onClose={onClose} {...baseProps} />);

        window.history.back();
        await settle();

        expect(onClose).toHaveBeenCalledOnce();
        expect(pageSawBack).not.toHaveBeenCalled();
        window.removeEventListener('popstate', pageSawBack);
    });

    it('closes on Escape and hands focus back to its trigger', async () => {
        const trigger = document.createElement('button');
        document.body.append(trigger);
        trigger.focus();
        const onClose = vi.fn();
        const { rerender } = render(
            <TemariNudgeDialog open onClose={onClose} {...baseProps} />,
        );

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });
        expect(onClose).toHaveBeenCalledOnce();
        rerender(
            <TemariNudgeDialog open={false} onClose={onClose} {...baseProps} />,
        );

        await waitFor(() => expect(trigger).toHaveFocus());
        trigger.remove();
    });
});
