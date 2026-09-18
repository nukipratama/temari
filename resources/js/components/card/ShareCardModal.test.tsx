import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// jsdom doesn't implement ClipboardItem
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};

import ShareCardModal, { type ShareCardTarget } from './ShareCardModal';

const card: ShareCardTarget = {
    activityId: 7,
    name: 'Counter Kick',
    shareUrl: '/activities/7',
    quote: 'that last kilometre was yours.',
};

/** The endpoint is the only source of pixels, so both CTAs fetch from it. */
function stubCardFetch() {
    const fetchSpy = vi.fn((url: string) =>
        Promise.resolve({
            ok: true,
            url,
            blob: () => Promise.resolve(new Blob(['i'], { type: 'image/png' })),
        } as Response),
    );
    globalThis.fetch = fetchSpy as unknown as typeof fetch;

    return fetchSpy;
}

function previewSrc(): string {
    return screen.getByRole('img').getAttribute('src') ?? '';
}

afterEach(() => {
    vi.restoreAllMocks();
    Object.defineProperty(navigator, 'share', {
        value: undefined,
        configurable: true,
    });
    Object.defineProperty(navigator, 'clipboard', {
        value: undefined,
        configurable: true,
    });
});

describe('ShareCardModal', () => {
    it('renders nothing without a card', () => {
        const { container } = render(
            <ShareCardModal card={null} onClose={vi.fn()} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('previews the server endpoint with the default style, aspect and facts', () => {
        render(<ShareCardModal card={card} onClose={vi.fn()} />);

        const src = previewSrc();
        expect(src).toContain('/activities/7/card.png');
        expect(src).toContain('style=broadsheet');
        expect(src).toContain('aspect=story');
        expect(src).toContain('hr=true');
        expect(src).toContain('elevation=true');
        expect(src).toContain('weather=true');
        expect(src).toContain('badges=true');
    });

    it('re-points the preview when the style or aspect changes', () => {
        render(<ShareCardModal card={card} onClose={vi.fn()} />);

        fireEvent.click(screen.getByText('topo plate'));
        expect(previewSrc()).toContain('style=topo');

        fireEvent.click(screen.getByText('square · 1:1'));
        expect(previewSrc()).toContain('aspect=feed');
        // The style survives the aspect change: the tuple is one URL.
        expect(previewSrc()).toContain('style=topo');
    });

    it('turns an optional fact off without touching the others', () => {
        render(<ShareCardModal card={card} onClose={vi.fn()} />);

        fireEvent.click(screen.getByText('elevation'));

        expect(previewSrc()).toContain('elevation=false');
        expect(previewSrc()).toContain('hr=true');
    });

    it('offers another style when the render fails instead of a broken image', () => {
        render(<ShareCardModal card={card} onClose={vi.fn()} />);

        fireEvent.error(screen.getByRole('img'));

        expect(screen.queryByRole('img')).toBeNull();
        expect(screen.getByRole('status').textContent).toContain(
            'try another style',
        );
    });

    it('copies the PNG the endpoint returns', async () => {
        const fetchSpy = stubCardFetch();
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', {
            value: { write },
            configurable: true,
        });

        render(<ShareCardModal card={card} onClose={vi.fn()} />);
        fireEvent.click(screen.getByText('Copy image'));

        await waitFor(() => expect(write).toHaveBeenCalled());
        expect(fetchSpy.mock.calls[0][0]).toContain('/activities/7/card.png');
        expect(screen.getByRole('status').textContent).toContain('copied');
    });

    it('shares the PNG as a file when the browser can', async () => {
        stubCardFetch();
        const share = vi.fn<(data: ShareData) => Promise<void>>(() =>
            Promise.resolve(),
        );
        Object.defineProperty(navigator, 'share', {
            value: share,
            configurable: true,
        });
        Object.defineProperty(navigator, 'canShare', {
            value: () => true,
            configurable: true,
        });

        render(<ShareCardModal card={card} onClose={vi.fn()} />);
        fireEvent.click(screen.getByText('Share'));

        await waitFor(() => expect(share).toHaveBeenCalled());
        expect(share.mock.calls[0][0]).toHaveProperty('files');
    });

    it('falls back to the activity link, carrying Temari line as the caption', async () => {
        stubCardFetch();
        const share = vi.fn<(data: ShareData) => Promise<void>>(() =>
            Promise.resolve(),
        );
        Object.defineProperty(navigator, 'share', {
            value: share,
            configurable: true,
        });
        Object.defineProperty(navigator, 'canShare', {
            value: () => false,
            configurable: true,
        });

        render(<ShareCardModal card={card} onClose={vi.fn()} />);
        fireEvent.click(screen.getByText('Share'));

        await waitFor(() => expect(share).toHaveBeenCalled());
        expect(share.mock.calls[0][0]).toMatchObject({
            url: '/activities/7',
            text: 'that last kilometre was yours.',
        });
    });
});
