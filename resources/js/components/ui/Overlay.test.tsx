import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { back, settle } from '@/test/overlayHistory';

import Overlay, { OverlayClose, OverlayTitle } from './Overlay';

beforeEach(() => {
    window.history.pushState({ page: 'current' }, '');
});

afterEach(async () => {
    cleanup();
    await settle();
});

function Stack() {
    const [outer, setOuter] = useState(false);
    const [inner, setInner] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOuter(true)}>
                open outer
            </button>
            <Overlay open={outer} onOpenChange={setOuter}>
                <OverlayTitle>outer</OverlayTitle>
                <button type="button" onClick={() => setInner(true)}>
                    open inner
                </button>
                <Overlay open={inner} onOpenChange={setInner}>
                    <OverlayTitle>inner</OverlayTitle>
                    <OverlayClose>close inner</OverlayClose>
                </Overlay>
            </Overlay>
        </>
    );
}

async function openBoth() {
    render(<Stack />);
    fireEvent.click(screen.getByText('open outer'));
    fireEvent.click(await screen.findByText('open inner'));
    await screen.findByRole('dialog', { name: 'inner' });
}

describe('Overlay', () => {
    it('renders a modal dialog labelled by its title', async () => {
        render(<Stack />);
        fireEvent.click(screen.getByText('open outer'));

        const dialog = await screen.findByRole('dialog', { name: 'outer' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
    });

    it('returns focus to the control that opened it', async () => {
        render(<Stack />);
        const trigger = screen.getByText('open outer');
        trigger.focus();
        fireEvent.click(trigger);
        await screen.findByRole('dialog', { name: 'outer' });

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });

        await waitFor(() => expect(trigger).toHaveFocus());
    });

    it('closes only the topmost overlay on Escape', async () => {
        await openBoth();

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });

        await waitFor(() =>
            expect(screen.queryByText('close inner')).not.toBeInTheDocument(),
        );
        expect(screen.getByText('open inner')).toBeInTheDocument();
    });

    it('closes only the topmost overlay on an outside press', async () => {
        await openBoth();
        // What a real press outside the inner popup lands on: Base UI's own guard layer in its portal.
        const outside = screen.getByRole('dialog', { name: 'inner' })
            .parentElement?.firstElementChild as HTMLElement;

        fireEvent.pointerDown(outside, { pointerId: 1 });
        fireEvent.mouseDown(outside);
        fireEvent.mouseUp(outside);
        fireEvent.click(outside);

        await waitFor(() =>
            expect(screen.queryByText('close inner')).not.toBeInTheDocument(),
        );
        expect(screen.getByText('open inner')).toBeInTheDocument();
    });

    it('closes one overlay per Back, topmost first', async () => {
        await openBoth();

        await back();
        expect(screen.queryByText('close inner')).not.toBeInTheDocument();
        expect(screen.getByText('open inner')).toBeInTheDocument();

        await back();
        expect(screen.queryByText('open inner')).not.toBeInTheDocument();
    });

    it('leaves no history entry behind when closed by button', async () => {
        await openBoth();
        fireEvent.click(screen.getByText('close inner'));
        await settle();

        await back();

        expect(screen.queryByText('open inner')).not.toBeInTheDocument();
    });
});
