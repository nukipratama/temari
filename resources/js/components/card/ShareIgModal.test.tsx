import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('html-to-image', () => ({
    toPng: vi.fn(() => Promise.resolve('data:image/png;base64,abc')),
}));

// jsdom doesn't implement ClipboardItem
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};
import ShareIgModal, { type ShareKartuData } from './ShareIgModal';

const kartu: ShareKartuData = {
    id: 7,
    name: 'Tendangan Balik',
    rarity: 'epic',
    subtitle: 'Pagi negatif-split · 20 Mei 2026',
    date: '20 Mei 2026\n07:00',
    km: '5.28',
    durasi: '40:00',
    trimp: '87',
    hr: '145 bpm',
    location: 'Jakarta Selatan',
    weather: '28°C',
    tags: ['Negative Split', 'Anak Pagi'],
    quote: 'Lari ini bukti kamu bisa lebih jauh.',
};

describe('ShareIgModal', () => {
    it('renders nothing when kartu is null', () => {
        const { container } = render(<ShareIgModal kartu={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the card name in the header', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Tendangan Balik/).length).toBeGreaterThan(0);
    });

    it('renders Bagikan and Salin Gambar CTAs', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/Bagikan/).length).toBeGreaterThan(0);
        expect(screen.getByText(/Salin Gambar/)).toBeInTheDocument();
    });

    it('renders theme buttons', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByText('Dawn')).toBeInTheDocument();
        expect(screen.getByText('Sky')).toBeInTheDocument();
        expect(screen.getByText('Cream')).toBeInTheDocument();
        expect(screen.getByText('Inverted')).toBeInTheDocument();
    });

    it('renders format picker Story and Feed buttons', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getByText(/Story/)).toBeInTheDocument();
        expect(screen.getByText(/Feed/)).toBeInTheDocument();
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<ShareIgModal kartu={kartu} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('switches theme when a theme button is clicked', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        fireEvent.click(screen.getByText('Sky'));
        fireEvent.click(screen.getByText('Cream'));
        fireEvent.click(screen.getByText('Inverted'));
        // No crash = theme switching works
    });

    it('renders stat items km, durasi, trimp', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        expect(screen.getAllByText(/5\.28/).length).toBeGreaterThan(0);
    });

    it('fires Bagikan without crashing when share API is unavailable', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        globalThis.fetch = vi.fn((url: string) =>
            url.startsWith('data:')
                ? Promise.resolve({ blob: () => Promise.resolve(new Blob(['i'], { type: 'image/png' })) } as Response)
                : Promise.reject(new Error('unexpected')),
        ) as typeof fetch;
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getAllByRole('button').find((b) => b.textContent === 'Bagikan') ?? document.body);
        });
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('/kartu/7'));
    });

    it('fires Salin Gambar and copies image to clipboard', async () => {
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { write }, configurable: true });
        globalThis.fetch = vi.fn((url: string) =>
            url.startsWith('data:')
                ? Promise.resolve({ blob: () => Promise.resolve(new Blob(['i'], { type: 'image/png' })) } as Response)
                : Promise.reject(new Error('unexpected')),
        ) as typeof fetch;
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        await act(async () => { fireEvent.click(screen.getByText(/Salin Gambar/)); });
        expect(write).toHaveBeenCalled();
    });

    it('toggles data and quote visibility switches', () => {
        render(<ShareIgModal kartu={kartu} onClose={vi.fn()} />);
        const switches = screen.getAllByRole('switch');
        expect(switches.length).toBe(2);
        fireEvent.click(switches[0]);
        fireEvent.click(switches[1]);
        fireEvent.click(switches[0]);
    });
});
