import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import Sheet, { SheetClose, SWIPE_DISMISS_PX } from './Sheet';

function Harness({ onOpenChange }: Readonly<{ onOpenChange?: () => void }>) {
    const [open, setOpen] = useState(true);

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                onOpenChange?.();
            }}
            title="something off?"
        >
            <p>sheet body</p>
            <SheetClose>never mind</SheetClose>
        </Sheet>
    );
}

function drag(distance: number) {
    const grip = screen.getByTestId('sheet-grip');

    fireEvent.pointerDown(grip, { clientY: 400, pointerId: 1 });
    fireEvent.pointerMove(grip, { clientY: 400 + distance, pointerId: 1 });
    fireEvent.pointerUp(grip, { clientY: 400 + distance, pointerId: 1 });
}

describe('Sheet', () => {
    it('renders its title and body when open', () => {
        render(<Harness />);

        expect(
            screen.getByRole('dialog', { name: 'something off?' }),
        ).toBeInTheDocument();
        expect(screen.getByText('sheet body')).toBeInTheDocument();
    });

    it('stays out of the document when closed', () => {
        render(
            <Sheet open={false} onOpenChange={vi.fn()} title="something off?">
                <p>sheet body</p>
            </Sheet>,
        );

        expect(screen.queryByText('sheet body')).not.toBeInTheDocument();
    });

    it('closes on the explicit close action', () => {
        render(<Harness />);

        fireEvent.click(screen.getByText('never mind'));

        expect(screen.queryByText('sheet body')).not.toBeInTheDocument();
    });

    it('closes on escape', () => {
        render(<Harness />);

        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByText('sheet body')).not.toBeInTheDocument();
    });

    it('closes on a scrim tap', () => {
        render(<Harness />);
        const scrim = screen.getByTestId('sheet-scrim');

        fireEvent.pointerDown(scrim, { pointerId: 1 });
        fireEvent.mouseDown(scrim);
        fireEvent.mouseUp(scrim);
        fireEvent.click(scrim);

        expect(screen.queryByText('sheet body')).not.toBeInTheDocument();
    });

    it('dismisses on a swipe past the threshold', () => {
        render(<Harness />);

        drag(SWIPE_DISMISS_PX + 10);

        expect(screen.queryByText('sheet body')).not.toBeInTheDocument();
    });

    it('stays open on a swipe short of the threshold', () => {
        render(<Harness />);

        drag(SWIPE_DISMISS_PX - 10);

        expect(screen.getByText('sheet body')).toBeInTheDocument();
    });

    it('ignores an upward drag', () => {
        render(<Harness />);

        drag(-200);

        expect(screen.getByText('sheet body')).toBeInTheDocument();
    });
});
