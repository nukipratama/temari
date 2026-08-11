import {
    render,
    screen,
    fireEvent,
    act,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The canvas renderer is unit-tested on its own; here we stub it so the modal
// tests don't depend on a real 2d context (jsdom doesn't implement one).
vi.mock('@/lib/shareCard', () => ({
    drawShareCard: vi.fn(() => Promise.resolve()),
    shareCardBlob: vi.fn(() =>
        Promise.resolve(new Blob(['x'], { type: 'image/png' })),
    ),
    // The modal only reads `.surface` (swatch preview colour) — the real
    // module has the full Palette shape, tested on its own in shareCard.test.ts.
    COLORWAYS: {
        navy: { surface: '#161b33' },
        dawn: { surface: '#f6f1e8' },
        ember: { surface: '#3a2015' },
    },
}));

// jsdom doesn't implement ClipboardItem
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};

// jsdom has no canvas backend, so toBlob is unimplemented and never calls back.
// The spy also records which canvas the modal exported from.
const toBlobSpy = vi.fn(function (this: HTMLCanvasElement, cb: BlobCallback) {
    cb(new Blob(['png'], { type: 'image/png' }));
});
HTMLCanvasElement.prototype.toBlob =
    toBlobSpy as unknown as HTMLCanvasElement['toBlob'];
import ShareCardModal, { type ShareKartuData } from './ShareCardModal';

// Both share paths fetch the rendered data: URL and turn it into a Blob.
// jsdom has no real fetch, so resolve data: URLs to a stub PNG blob.
function stubDataUrlFetch() {
    globalThis.fetch = vi.fn((url: string) =>
        url.startsWith('data:')
            ? Promise.resolve({
                  blob: () =>
                      Promise.resolve(new Blob(['i'], { type: 'image/png' })),
              } as Response)
            : Promise.reject(new Error('unexpected')),
    ) as typeof fetch;
}

const kartu: ShareKartuData = {
    id: 7,
    name: 'Counter Kick',
    shareUrl: '/activities/7',
    rarity: 'epic',
    mood: 'easy',
    subtitle: 'Negative-split morning · 20 Mei 2026',
    date: '20 Mei 2026\n07:00',
    km: '5.28',
    durasi: '40 min',
    pace: '5:30',
    trimp: '87',
    hr: '145 bpm',
    cadence: '176 spm',
    fastestKm: '5:02/km',
    zonePct: { Z1: 8, Z2: 35, Z3: 32, Z4: 18, Z5: 7 },
    location: 'Jakarta Selatan',
    weather: '28°C',
    tags: ['Negative Split', 'Early Bird'],
    tagEmojis: ['👻', '🌅'],
    quote: 'This run proves you can go further.',
    polyline: '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    edition: { index: 3, total: 25 },
};

describe('ShareCardModal', () => {
    it('renders nothing when kartu is null', () => {
        const { container } = render(
            <ShareCardModal kartu={null} onClose={vi.fn()} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders the card name in the header', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Counter Kick/).length).toBeGreaterThan(0);
    });

    it('renders Share and Copy Image CTAs', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Share/).length).toBeGreaterThan(0);
        expect(screen.getByText(/Copy image/)).toBeInTheDocument();
    });

    it('renders format picker Portrait and Square buttons', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByText(/Portrait/)).toBeInTheDocument();
        expect(screen.getByText(/Square/)).toBeInTheDocument();
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<ShareCardModal kartu={kartu} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Close'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders the canvas preview', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByLabelText(/Preview of/)).toBeInTheDocument();
    });

    it('moves focus into the dialog when it opens', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('fires Share without crashing when share API is unavailable', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'share', {
            value: undefined,
            configurable: true,
        });
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        stubDataUrlFetch();
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(
                screen
                    .getAllByRole('button')
                    .find((b) => b.textContent === 'Share') ?? document.body,
            );
        });
        expect(writeText).toHaveBeenCalledWith(
            expect.stringContaining('/activities/7'),
        );
    });

    it('fires Copy Image and copies image to clipboard', async () => {
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', {
            value: { write },
            configurable: true,
        });
        stubDataUrlFetch();
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText(/Copy image/));
        });
        expect(write).toHaveBeenCalled();
    });

    it('offers the share templates as buttons and switches between them', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        const kartuBtn = screen.getByRole('button', { name: 'Card' });
        const ruteBtn = screen.getByRole('button', { name: 'Route' });
        const statsBtn = screen.getByRole('button', { name: 'Stats' });
        expect(kartuBtn).toBeInTheDocument();
        expect(ruteBtn).toBeInTheDocument();
        expect(statsBtn).toBeInTheDocument();
        // The dropdown and the trimmed Struk template are gone.
        expect(screen.queryByLabelText('Pilih gaya kartu')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Receipt' })).toBeNull();
        // Switching to the route template renders without crashing.
        fireEvent.click(ruteBtn);
        expect(screen.getAllByText(/Counter Kick/).length).toBeGreaterThan(0);
    });

    it('hides only the Route template for a no-GPS run, keeping Card and Stats', () => {
        render(
            <ShareCardModal
                kartu={{ ...kartu, polyline: null }}
                onClose={vi.fn()}
            />,
        );
        // Route needs a polyline the run doesn't have; Card and Stats don't.
        expect(screen.queryByRole('button', { name: 'Route' })).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Card' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Stats' }),
        ).toBeInTheDocument();
    });

    it('clamps a stale stats-incompatible layout back to kartu for a no-GPS run, same as rute', () => {
        const { rerender } = render(
            <ShareCardModal kartu={kartu} onClose={vi.fn()} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Route' }));
        rerender(
            <ShareCardModal
                kartu={{ ...kartu, polyline: null }}
                onClose={vi.fn()}
            />,
        );
        // The Route button is gone, and Card is selectable again — the stale
        // selection didn't strand the picker on a hidden option.
        expect(screen.queryByRole('button', { name: 'Route' })).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Card', pressed: true }),
        ).toBeInTheDocument();
    });

    it('clamps a stale rute layout to kartu for a no-GPS run so the map is never blank', async () => {
        const { drawShareCard } = await import('@/lib/shareCard');
        vi.mocked(drawShareCard).mockClear();
        const { rerender } = render(
            <ShareCardModal kartu={kartu} onClose={vi.fn()} />,
        );
        // Pick the route template on a GPS card, then reuse the same modal for a
        // no-GPS run: the carried-over 'rute' selection must not paint a blank map.
        fireEvent.click(screen.getByRole('button', { name: 'Route' }));
        rerender(
            <ShareCardModal
                kartu={{ ...kartu, polyline: null }}
                onClose={vi.fn()}
            />,
        );
        const lastCall = vi.mocked(drawShareCard).mock.calls.at(-1);
        expect(lastCall?.[1].layout).toBe('kartu');
    });

    describe('colorway swatch picker', () => {
        it('offers navy, dawn, and ember swatches with navy selected by default', () => {
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            expect(
                screen.getByRole('button', {
                    name: 'Colorway: Navy',
                    pressed: true,
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: 'Colorway: Dawn',
                    pressed: false,
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: 'Colorway: Ember',
                    pressed: false,
                }),
            ).toBeInTheDocument();
        });

        it('switches the drawn colorway when a swatch is clicked', async () => {
            const { drawShareCard } = await import('@/lib/shareCard');
            vi.mocked(drawShareCard).mockClear();
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Colorway: Ember' }),
            );
            const lastCall = vi.mocked(drawShareCard).mock.calls.at(-1);
            expect(lastCall?.[1].colorway).toBe('ember');
            expect(
                screen.getByRole('button', {
                    name: 'Colorway: Ember',
                    pressed: true,
                }),
            ).toBeInTheDocument();
        });
    });

    describe('export source', () => {
        const originalClipboard = Object.getOwnPropertyDescriptor(
            navigator,
            'clipboard',
        );

        afterEach(() => {
            if (originalClipboard) {
                Object.defineProperty(
                    navigator,
                    'clipboard',
                    originalClipboard,
                );
            }
        });

        it('exports the preview canvas itself instead of redrawing the card into a second one', async () => {
            const { shareCardBlob } = await import('@/lib/shareCard');
            vi.mocked(shareCardBlob).mockClear();
            toBlobSpy.mockClear();
            const write = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'clipboard', {
                value: { write },
                configurable: true,
            });

            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                fireEvent.click(screen.getByText(/Copy image/));
            });

            expect(write).toHaveBeenCalled();
            expect(shareCardBlob).not.toHaveBeenCalled();
            expect(toBlobSpy).toHaveBeenCalledWith(
                expect.any(Function),
                'image/png',
            );
            // Same element as the on-screen preview, at the full 1080x1920 export
            // resolution — the shared PNG must not silently drop to preview size.
            const exported = toBlobSpy.mock.instances[0];
            expect(exported).toBe(screen.getByLabelText(/Preview of/));
            expect(exported.width).toBe(1080);
            expect(exported.height).toBe(1920);
        });
    });

    it('switches the export format when a format button is clicked', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        const canvas = screen.getByLabelText(/Preview of/) as HTMLCanvasElement;
        // Story (9:16) is the default — the canvas is 1080x1920.
        expect(canvas.height).toBe(1920);
        fireEvent.click(screen.getByText(/Square/));
        // Switching to feed (1:1) repaints the canvas at 1080x1080.
        expect(canvas.height).toBe(1080);
    });

    describe('native share (Web Share API)', () => {
        const original = {
            share: Object.getOwnPropertyDescriptor(navigator, 'share'),
            clipboard: Object.getOwnPropertyDescriptor(navigator, 'clipboard'),
        };

        afterEach(() => {
            if (original.share) {
                Object.defineProperty(navigator, 'share', original.share);
            }
            if (original.clipboard) {
                Object.defineProperty(
                    navigator,
                    'clipboard',
                    original.clipboard,
                );
            }
        });

        function clickShare() {
            return fireEvent.click(
                screen
                    .getAllByRole('button')
                    .find((b) => b.textContent === 'Share') ?? document.body,
            );
        }

        it('shares the rendered image file when the platform can share files', async () => {
            const share = vi.fn(() => Promise.resolve());
            const canShare = vi.fn(() => true);
            Object.defineProperty(navigator, 'share', {
                value: share,
                configurable: true,
            });
            Object.defineProperty(navigator, 'canShare', {
                value: canShare,
                configurable: true,
            });
            stubDataUrlFetch();
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                clickShare();
            });
            expect(canShare).toHaveBeenCalledWith({
                files: [expect.any(File)],
            });
            expect(share).toHaveBeenCalledWith(
                expect.objectContaining({ files: [expect.any(File)] }),
            );
        });

        it('falls back to a URL share when files cannot be shared', async () => {
            const share = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'share', {
                value: share,
                configurable: true,
            });
            // canShare returns false → file share skipped, URL share path taken.
            Object.defineProperty(navigator, 'canShare', {
                value: () => false,
                configurable: true,
            });
            stubDataUrlFetch();
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                clickShare();
            });
            expect(share).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: expect.stringContaining('/activities/7'),
                    // The card has a quote, so it rides along as the share text.
                    text: kartu.quote,
                }),
            );
        });

        it('uses the rarity label as share text when the card has no quote', async () => {
            const share = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'share', {
                value: share,
                configurable: true,
            });
            Object.defineProperty(navigator, 'canShare', {
                value: () => false,
                configurable: true,
            });
            stubDataUrlFetch();
            // quote=null exercises the `?? RARITY_LABELS[...]` fallback.
            render(
                <ShareCardModal
                    kartu={{ ...kartu, quote: null }}
                    onClose={vi.fn()}
                />,
            );
            await act(async () => {
                clickShare();
            });
            expect(share).toHaveBeenCalledWith(
                expect.objectContaining({
                    text: expect.stringContaining(kartu.name),
                }),
            );
        });

        it('shows a copied-link toast when share is unavailable but clipboard works', async () => {
            const writeText = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'share', {
                value: undefined,
                configurable: true,
            });
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText },
                configurable: true,
            });
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                clickShare();
            });
            expect(
                await screen.findByText('Activity link copied.'),
            ).toBeInTheDocument();
        });

        it('shows an error toast when copying the link to the clipboard fails', async () => {
            const writeText = vi.fn(() => Promise.reject(new Error('denied')));
            Object.defineProperty(navigator, 'share', {
                value: undefined,
                configurable: true,
            });
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText },
                configurable: true,
            });
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                clickShare();
            });
            expect(
                await screen.findByText('Failed to copy link.'),
            ).toBeInTheDocument();
        });

        it('shows an unsupported toast when neither share nor clipboard exist', async () => {
            Object.defineProperty(navigator, 'share', {
                value: undefined,
                configurable: true,
            });
            Object.defineProperty(navigator, 'clipboard', {
                value: undefined,
                configurable: true,
            });
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                clickShare();
            });
            expect(
                await screen.findByText(
                    "This browser doesn't support sharing.",
                ),
            ).toBeInTheDocument();
        });
    });

    describe('copy image', () => {
        const originalClipboard = Object.getOwnPropertyDescriptor(
            navigator,
            'clipboard',
        );
        const originalClipboardItem = (
            globalThis as { ClipboardItem?: unknown }
        ).ClipboardItem;

        afterEach(() => {
            if (originalClipboard) {
                Object.defineProperty(
                    navigator,
                    'clipboard',
                    originalClipboard,
                );
            }
            (globalThis as { ClipboardItem?: unknown }).ClipboardItem =
                originalClipboardItem;
        });

        it('shows an unsupported toast when ClipboardItem is missing', async () => {
            (globalThis as { ClipboardItem?: unknown }).ClipboardItem =
                undefined;
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                fireEvent.click(screen.getByText(/Copy image/));
            });
            expect(
                await screen.findByText(/doesn't support copying images/),
            ).toBeInTheDocument();
        });

        it('shows an error toast when writing the image to the clipboard fails', async () => {
            const write = vi.fn(() => Promise.reject(new Error('blocked')));
            Object.defineProperty(navigator, 'clipboard', {
                value: { write },
                configurable: true,
            });
            stubDataUrlFetch();
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                fireEvent.click(screen.getByText(/Copy image/));
            });
            expect(
                await screen.findByText(/Failed to copy image/),
            ).toBeInTheDocument();
        });
    });

    describe('transient status effect', () => {
        afterEach(() => {
            vi.useRealTimers();
            vi.restoreAllMocks();
        });

        it('auto-clears the status toast after its timeout', async () => {
            const write = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'clipboard', {
                value: { write },
                configurable: true,
            });
            stubDataUrlFetch();
            render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
            await act(async () => {
                fireEvent.click(screen.getByText(/Copy image/));
            });
            expect(
                await screen.findByText('Card image copied.'),
            ).toBeInTheDocument();
            // The status line is a transient toast; it removes itself after 2.6s.
            await waitFor(
                () =>
                    expect(screen.queryByText('Card image copied.')).toBeNull(),
                { timeout: 4000 },
            );
        });
    });
});
