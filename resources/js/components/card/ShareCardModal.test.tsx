import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { Print } from '@/lib/card/print';

import { makeCardFacts } from '@/test/cardFacts';

import ShareCardModal, { type ShareCardTarget } from './ShareCardModal';

// jsdom implements neither of these.
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};

const renderPrint = vi.hoisted(() => vi.fn());
vi.mock('@/lib/card/print', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/card/print')>()),
    renderPrint,
}));

function print(url = 'blob:print'): Print {
    return {
        blob: new Blob(['png'], { type: 'image/png' }),
        url,
        width: 1080,
        height: 1920,
    };
}

const card: ShareCardTarget = {
    name: 'Counter Kick',
    facts: makeCardFacts(),
    shareUrl: '/activities/7',
    quote: 'that last kilometre was yours.',
};

async function openModal(target: ShareCardTarget = card) {
    render(<ShareCardModal card={target} onClose={vi.fn()} />);
    await waitFor(() =>
        expect(screen.getAllByRole('img').length).toBeGreaterThan(0),
    );
}

beforeEach(() => {
    renderPrint.mockReset();
    renderPrint.mockResolvedValue(print());
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:stub');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
    for (const key of ['share', 'canShare', 'clipboard'] as const) {
        Object.defineProperty(navigator, key, {
            value: undefined,
            configurable: true,
        });
    }
});

describe('ShareCardModal', () => {
    it('renders nothing without a card', () => {
        const { container } = render(
            <ShareCardModal card={null} onClose={vi.fn()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws all three prints so the neighbours can peek past the stage', async () => {
        await openModal();

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));
        expect(screen.getByText('broadsheet')).toBeInTheDocument();
        expect(screen.getByText('ticket')).toBeInTheDocument();
        expect(screen.getByText('topo plate')).toBeInTheDocument();
    });

    it('redraws nothing when switching back to a print it already made', async () => {
        await openModal();
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));

        fireEvent.click(screen.getByText('ticket'));
        fireEvent.click(screen.getByText('broadsheet'));

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));
    });

    it('redraws when a fact chip changes the print', async () => {
        await openModal();
        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(3));

        fireEvent.click(screen.getByText('elevation'));

        await waitFor(() => expect(renderPrint).toHaveBeenCalledTimes(6));
    });

    it('hides a chip for a fact the run does not have, rather than disabling it', async () => {
        await openModal({
            ...card,
            facts: makeCardFacts({ heart_rate: null, badges: [] }),
        });

        expect(screen.queryByText('HR')).not.toBeInTheDocument();
        expect(screen.queryByText('badges')).not.toBeInTheDocument();
        expect(screen.getByText('weather')).toBeInTheDocument();
    });

    it('offers the safe-zone note on a story and drops it on a feed', async () => {
        await openModal();

        expect(
            screen.getByText('shaded bands = what story apps cover'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /feed/ }));

        await waitFor(() =>
            expect(
                screen.queryByText('shaded bands = what story apps cover'),
            ).not.toBeInTheDocument(),
        );
    });

    it('walks the rack with the arrow keys', async () => {
        await openModal();
        const stage = screen.getByRole('group', { name: 'print style' });

        fireEvent.keyDown(stage, { key: 'ArrowRight' });
        expect(screen.getByRole('button', { name: 'ticket' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        fireEvent.keyDown(stage, { key: 'ArrowLeft' });
        expect(
            screen.getByRole('button', { name: 'broadsheet' }),
        ).toHaveAttribute('aria-pressed', 'true');

        // The ends hold: there is no fourth style to walk onto.
        fireEvent.keyDown(stage, { key: 'ArrowLeft' });
        expect(
            screen.getByRole('button', { name: 'broadsheet' }),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    it('swipes between prints', async () => {
        await openModal();
        const stage = screen.getByRole('group', { name: 'print style' });

        fireEvent.touchStart(stage, { touches: [{ clientX: 200 }] });
        fireEvent.touchEnd(stage, { changedTouches: [{ clientX: 100 }] });

        expect(screen.getByRole('button', { name: 'ticket' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        // A nudge too small to be a swipe leaves the rack where it was.
        fireEvent.touchStart(stage, { touches: [{ clientX: 200 }] });
        fireEvent.touchEnd(stage, { changedTouches: [{ clientX: 190 }] });

        expect(screen.getByRole('button', { name: 'ticket' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('offers a retry when a print cannot be made', async () => {
        renderPrint.mockRejectedValue(new Error('boom'));
        render(<ShareCardModal card={card} onClose={vi.fn()} />);

        await waitFor(() =>
            expect(
                screen.getAllByText("couldn't make the print.").length,
            ).toBeGreaterThan(0),
        );

        renderPrint.mockResolvedValue(print());
        fireEvent.click(screen.getAllByText('try again')[0]);

        await waitFor(() =>
            expect(screen.getAllByRole('img').length).toBeGreaterThan(0),
        );
    });

    it('shares the print itself as a file when the sheet takes one', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'share', {
            value: share,
            configurable: true,
        });
        Object.defineProperty(navigator, 'canShare', {
            value: () => true,
            configurable: true,
        });
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /share/ }));

        await waitFor(() => expect(share).toHaveBeenCalled());
        const files = share.mock.calls[0][0].files as File[];
        expect(files[0].type).toBe('image/png');
        expect(files[0].name).toBe('counter-kick-broadsheet.png');
    });

    it('falls back to the run link when the sheet will not take a file', async () => {
        const share = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'share', {
            value: share,
            configurable: true,
        });
        Object.defineProperty(navigator, 'canShare', {
            value: () => false,
            configurable: true,
        });
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /share/ }));

        await waitFor(() => expect(share).toHaveBeenCalled());
        expect(share.mock.calls[0][0].url).toBe('/activities/7');
    });

    it('copies the link when the browser has no share sheet at all', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /share/ }));

        await waitFor(() =>
            expect(writeText).toHaveBeenCalledWith('/activities/7'),
        );
        expect(await screen.findByText('run link copied.')).toBeInTheDocument();
    });

    it('says so when the browser can neither share nor copy', async () => {
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /share/ }));

        expect(
            await screen.findByText("this browser can't share."),
        ).toBeInTheDocument();
    });

    it('copies the print image itself', async () => {
        const write = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { write },
            configurable: true,
        });
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /copy/ }));

        await waitFor(() => expect(write).toHaveBeenCalled());
        expect(await screen.findByText('print copied.')).toBeInTheDocument();
    });

    it('says so when the clipboard refuses the image', async () => {
        const write = vi.fn().mockRejectedValue(new Error('denied'));
        Object.defineProperty(navigator, 'clipboard', {
            value: { write },
            configurable: true,
        });
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /copy/ }));

        expect(
            await screen.findByText(
                "couldn't copy the print. try share instead.",
            ),
        ).toBeInTheDocument();
    });

    it('says so when the browser cannot copy an image at all', async () => {
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /copy/ }));

        expect(
            await screen.findByText(
                "this browser can't copy images. use share instead.",
            ),
        ).toBeInTheDocument();
    });

    it('downloads the print under the run and style name', async () => {
        const click = vi.fn();
        const anchor = { href: '', download: '', click };
        vi.spyOn(document, 'createElement').mockImplementation(
            (tag: string) =>
                (tag === 'a'
                    ? anchor
                    : document.createElementNS(
                          'http://www.w3.org/1999/xhtml',
                          tag,
                      )) as HTMLElement,
        );
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: /download/ }));

        expect(click).toHaveBeenCalled();
        expect(anchor.download).toBe('counter-kick-broadsheet.png');
    });

    it('closes on the close button', async () => {
        const onClose = vi.fn();
        render(<ShareCardModal card={card} onClose={onClose} />);

        fireEvent.click(screen.getByRole('button', { name: 'close' }));

        expect(onClose).toHaveBeenCalled();
    });
});
