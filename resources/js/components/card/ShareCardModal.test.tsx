import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// The canvas renderer is unit-tested on its own; here we stub it so the modal
// tests don't depend on a real 2d context (jsdom doesn't implement one).
vi.mock('@/lib/shareCard', () => ({
    drawShareCard: vi.fn(() => Promise.resolve()),
    shareCardBlob: vi.fn(() => Promise.resolve(new Blob(['x'], { type: 'image/png' }))),
}));

// jsdom doesn't implement ClipboardItem
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};
import ShareCardModal, { type ShareKartuData } from './ShareCardModal';

// Both share paths fetch the rendered data: URL and turn it into a Blob.
// jsdom has no real fetch, so resolve data: URLs to a stub PNG blob.
function stubDataUrlFetch() {
    globalThis.fetch = vi.fn((url: string) =>
        url.startsWith('data:')
            ? Promise.resolve({ blob: () => Promise.resolve(new Blob(['i'], { type: 'image/png' })) } as Response)
            : Promise.reject(new Error('unexpected')),
    ) as typeof fetch;
}

const kartu: ShareKartuData = {
    id: 7,
    name: 'Tendangan Balik',
    rarity: 'epic',
    mood: 'enteng',
    subtitle: 'Pagi negatif-split · 20 Mei 2026',
    date: '20 Mei 2026\n07:00',
    km: '5.28',
    durasi: '40 menit',
    pace: '5:30',
    trimp: '87',
    hr: '145 bpm',
    cadence: '176 spm',
    fastestKm: '5:02/km',
    zonePct: { Z1: 8, Z2: 35, Z3: 32, Z4: 18, Z5: 7 },
    location: 'Jakarta Selatan',
    weather: '28°C',
    tags: ['Negative Split', 'Anak Pagi'],
    tagEmojis: ['👻', '🌅'],
    quote: 'Lari ini bukti kamu bisa lebih jauh.',
    polyline: '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    edition: { index: 3, total: 25 },
};

describe('ShareCardModal', () => {
    it('renders nothing when kartu is null', () => {
        const { container } = render(<ShareCardModal kartu={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the card name in the header', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Tendangan Balik/).length).toBeGreaterThan(0);
    });

    it('renders Bagikan and Salin Gambar CTAs', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Bagikan/).length).toBeGreaterThan(0);
        expect(screen.getByText(/Salin gambar/)).toBeInTheDocument();
    });

    it('renders format picker Potret and Persegi buttons', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByText(/Potret/)).toBeInTheDocument();
        expect(screen.getByText(/Persegi/)).toBeInTheDocument();
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<ShareCardModal kartu={kartu} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders the canvas preview', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByLabelText(/Pratinjau kartu/)).toBeInTheDocument();
    });

    it('fires Bagikan without crashing when share API is unavailable', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        stubDataUrlFetch();
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getAllByRole('button').find((b) => b.textContent === 'Bagikan') ?? document.body);
        });
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('/kartu/7'));
    });

    it('fires Salin Gambar and copies image to clipboard', async () => {
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { write }, configurable: true });
        stubDataUrlFetch();
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => { fireEvent.click(screen.getByText(/Salin gambar/)); });
        expect(write).toHaveBeenCalled();
    });

    it('offers the share templates as buttons and switches between them', () => {
        render(<ShareCardModal kartu={kartu} onClose={vi.fn()} />);
        const kartuBtn = screen.getByRole('button', { name: 'Kartu' });
        const ruteBtn = screen.getByRole('button', { name: 'Rute' });
        expect(kartuBtn).toBeInTheDocument();
        expect(ruteBtn).toBeInTheDocument();
        // The dropdown and the trimmed Struk template are gone.
        expect(screen.queryByLabelText('Pilih gaya kartu')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Struk' })).toBeNull();
        // Switching to the route template renders without crashing.
        fireEvent.click(ruteBtn);
        expect(screen.getAllByText(/Tendangan Balik/).length).toBeGreaterThan(0);
    });

    it('hides the Gaya picker when the card has no route', () => {
        render(<ShareCardModal kartu={{ ...kartu, polyline: null }} onClose={vi.fn()} />);
        // Only Kartu remains, so there's nothing to pick — the picker is hidden.
        expect(screen.queryByRole('button', { name: 'Rute' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Kartu' })).toBeNull();
        expect(screen.queryByText('Gaya')).toBeNull();
    });
});
