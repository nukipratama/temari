import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// The canvas renderer is unit-tested on its own; stub it so the modal tests
// don't depend on a real 2d context (jsdom doesn't implement one).
vi.mock('@/lib/recapShare', () => ({
    drawRecapShare: vi.fn(() => Promise.resolve()),
    recapShareBlob: vi.fn(() => Promise.resolve(new Blob(['x'], { type: 'image/png' }))),
}));

// jsdom doesn't implement ClipboardItem.
(globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = class {
    constructor(public data: Record<string, Blob | Promise<Blob>>) {}
};

import RecapShareModal from './RecapShareModal';
import { recapShareBlob } from '@/lib/recapShare';
import type { RecapShareData } from '@/lib/recapShare';

const recap: RecapShareData = {
    weekStart: '2026-05-11',
    weekEnd: '2026-05-17',
    kmLabel: '32.4',
    runs: 4,
    deltaPct: 12,
    streakWeeks: 3,
    bestCardMove: 'Pemburu Sabar',
    bestCardRarity: 'legendary',
    nearestGoalTitle: 'Catat 10 lari',
    nearestGoalRemainder: '2 lari lagi',
};

describe('RecapShareModal', () => {
    it('renders nothing when recap is null', () => {
        const { container } = render(<RecapShareModal recap={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders a dialog with the Minggu Kamu header', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Minggu Kamu')).toBeInTheDocument();
    });

    it('renders the Bagikan, Simpan, and Salin gambar CTAs', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        expect(screen.getByText('Bagikan')).toBeInTheDocument();
        expect(screen.getByText('Simpan')).toBeInTheDocument();
        expect(screen.getByText('Salin gambar')).toBeInTheDocument();
    });

    it('renders the format picker Potret and Persegi buttons', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        expect(screen.getByText(/Potret/)).toBeInTheDocument();
        expect(screen.getByText(/Persegi/)).toBeInTheDocument();
    });

    it('renders the preview canvas', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        expect(screen.getByLabelText(/Pratinjau gambar minggu kamu/)).toBeInTheDocument();
    });

    it('moves focus into the dialog when it opens (focus trap)', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('calls onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<RecapShareModal recap={recap} onClose={onClose} />);
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('calls onClose on Escape (keyboard a11y)', () => {
        const onClose = vi.fn();
        render(<RecapShareModal recap={recap} onClose={onClose} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalled();
    });

    it('switches the export format when a format button is clicked', () => {
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        const canvas = screen.getByLabelText(/Pratinjau gambar minggu kamu/) as HTMLCanvasElement;
        expect(canvas.height).toBe(1920); // story (9:16) default
        fireEvent.click(screen.getByText(/Persegi/));
        expect(canvas.height).toBe(1080); // feed (1:1)
    });

    it('copies the image to the clipboard on Salin gambar', async () => {
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { write }, configurable: true });
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Salin gambar'));
        });
        expect(write).toHaveBeenCalled();
        expect(screen.getByRole('status')).toHaveTextContent(/kesalin/);
    });

    it('downloads the image on Simpan without crashing', async () => {
        const click = vi.fn();
        const origCreate = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
            const el = origCreate(tag) as HTMLElement;
            if (tag === 'a') {
                (el as HTMLAnchorElement).click = click;
            }
            return el;
        });
        globalThis.URL.createObjectURL = vi.fn(() => 'blob:x');
        globalThis.URL.revokeObjectURL = vi.fn();
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Simpan'));
        });
        expect(click).toHaveBeenCalled();
        vi.restoreAllMocks();
    });

    it('shares via the Web Share API when available, falling back to download otherwise', async () => {
        const share = vi.fn(() => Promise.resolve());
        const canShare = vi.fn(() => true);
        Object.defineProperty(navigator, 'share', { value: share, configurable: true });
        Object.defineProperty(navigator, 'canShare', { value: canShare, configurable: true });
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Bagikan'));
        });
        expect(share).toHaveBeenCalledWith({ files: [expect.any(File)], title: 'Minggu Kamu · TemanLari' });
        Reflect.deleteProperty(navigator, 'share');
        Reflect.deleteProperty(navigator, 'canShare');
    });

    it('shows a fallback message when the browser has no ClipboardItem support', async () => {
        const original = (globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem;
        Reflect.deleteProperty(globalThis, 'ClipboardItem');
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Salin gambar'));
        });
        expect(screen.getByRole('status')).toHaveTextContent(/belum dukung salin gambar/);
        (globalThis as unknown as { ClipboardItem: unknown }).ClipboardItem = original;
    });

    it('shows an error status when copying the image fails', async () => {
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { write }, configurable: true });
        vi.mocked(recapShareBlob).mockRejectedValueOnce(new Error('boom'));
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Salin gambar'));
        });
        expect(screen.getByRole('status')).toHaveTextContent(/Gagal nyalin gambar/);
    });

    it('falls back to download when navigator.share rejects', async () => {
        const share = vi.fn(() => Promise.reject(new Error('cancelled')));
        const canShare = vi.fn(() => true);
        Object.defineProperty(navigator, 'share', { value: share, configurable: true });
        Object.defineProperty(navigator, 'canShare', { value: canShare, configurable: true });
        const click = vi.fn();
        const origCreate = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
            const el = origCreate(tag) as HTMLElement;
            if (tag === 'a') {
                (el as HTMLAnchorElement).click = click;
            }
            return el;
        });
        globalThis.URL.createObjectURL = vi.fn(() => 'blob:x');
        globalThis.URL.revokeObjectURL = vi.fn();
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Bagikan'));
        });
        expect(share).toHaveBeenCalled();
        expect(click).toHaveBeenCalled();
        expect(screen.getByRole('status')).toHaveTextContent(/kesimpen/);
        Reflect.deleteProperty(navigator, 'share');
        Reflect.deleteProperty(navigator, 'canShare');
        vi.restoreAllMocks();
    });

    it('auto-clears the status toast after its timeout', async () => {
        vi.useFakeTimers();
        const write = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { write }, configurable: true });
        render(<RecapShareModal recap={recap} onClose={vi.fn()} />);
        await act(async () => {
            fireEvent.click(screen.getByText('Salin gambar'));
        });
        expect(screen.getByRole('status')).toHaveTextContent(/kesalin/);

        await act(async () => {
            vi.advanceTimersByTime(2600);
        });
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        vi.useRealTimers();
    });
});
