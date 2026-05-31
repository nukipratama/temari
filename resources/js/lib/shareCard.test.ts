import { beforeEach, describe, expect, it, vi } from 'vitest';
import { drawShareCard, shareCardBlob, type Layout, type Format, type ShareKartuData } from './shareCard';

const kartu: ShareKartuData = {
    id: 1,
    name: 'Pemburu Sabar',
    rarity: 'legendary',
    subtitle: null,
    date: '30 Mei 2026\n06:30',
    km: '42.61',
    durasi: '6 jam 8 menit',
    pace: '5:48',
    trimp: '913',
    hr: '164 bpm',
    location: 'Gelora Bung Karno, Jakarta',
    weather: '27°C',
    tags: ['Anak Pagi'],
    quote: 'Kartu ini lahir dari sesi yang tenang tapi solid.',
    polyline: '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    edition: { index: 2, total: 7 },
};

function makeCtx() {
    const gradient = { addColorStop: vi.fn() };
    return {
        clearRect: vi.fn(),
        fillRect: vi.fn(),
        fillText: vi.fn(),
        measureText: vi.fn(() => ({ width: 120 })),
        createLinearGradient: vi.fn(() => gradient),
        createRadialGradient: vi.fn(() => gradient),
        beginPath: vi.fn(),
        closePath: vi.fn(),
        moveTo: vi.fn(),
        lineTo: vi.fn(),
        arc: vi.fn(),
        arcTo: vi.fn(),
        fill: vi.fn(),
        stroke: vi.fn(),
        clip: vi.fn(),
        save: vi.fn(),
        restore: vi.fn(),
        translate: vi.fn(),
        rotate: vi.fn(),
        drawImage: vi.fn(),
        setLineDash: vi.fn(),
        fillStyle: '',
        strokeStyle: '',
        lineWidth: 0,
        lineCap: '',
        lineJoin: '',
        globalAlpha: 1,
        font: '',
        textAlign: '',
        textBaseline: '',
        letterSpacing: '',
        shadowColor: '',
        shadowBlur: 0,
        shadowOffsetY: 0,
    };
}

beforeEach(() => {
    Object.defineProperty(document, 'fonts', {
        value: { load: vi.fn(() => Promise.resolve()), ready: Promise.resolve() },
        configurable: true,
    });
    // jsdom never fires image load; resolve immediately so loadBunny settles.
    class FakeImage {
        onload: (() => void) | null = null;
        onerror: (() => void) | null = null;
        set src(_v: string) {
            queueMicrotask(() => this.onload?.());
        }
    }
    (globalThis as unknown as { Image: unknown }).Image = FakeImage;
});

describe('drawShareCard', () => {
    const layouts: Layout[] = ['kartu', 'pack', 'rute', 'polaroid', 'poster', 'struk'];
    const formats: Format[] = ['story', 'feed'];

    it.each(layouts)('paints the %s layout at the fixed story resolution', async (layout) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, theme: 'Dawn', layout, format: 'story', showStats: true, showQuote: true });
        expect(canvas.width).toBe(1080);
        expect(canvas.height).toBe(1920);
        expect(ctx.fillRect).toHaveBeenCalled(); // background painted
        expect(ctx.fillText).toHaveBeenCalled(); // text drawn
    });

    it.each(formats)('uses the right canvas size for %s', async (format) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, theme: 'Cream', layout: 'poster', format, showStats: true, showQuote: false });
        expect(canvas.height).toBe(format === 'story' ? 1920 : 1080);
    });

    it('does not throw when the 2d context is unavailable', async () => {
        const canvas = { width: 0, height: 0, getContext: () => null } as unknown as HTMLCanvasElement;
        await expect(
            drawShareCard(canvas, { kartu, theme: 'Sky', layout: 'struk', format: 'feed', showStats: false, showQuote: false }),
        ).resolves.toBeUndefined();
    });
});

describe('shareCardBlob', () => {
    it('renders onto an offscreen canvas and resolves the PNG blob', async () => {
        const ctx = makeCtx();
        const blob = new Blob(['png'], { type: 'image/png' });
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(blob),
        };
        vi.spyOn(document, 'createElement').mockReturnValue(canvas as unknown as HTMLCanvasElement);

        await expect(
            shareCardBlob({ kartu, theme: 'Dawn', layout: 'kartu', format: 'story', showStats: true, showQuote: true }),
        ).resolves.toBe(blob);
    });

    it('rejects when the canvas yields no blob', async () => {
        const ctx = makeCtx();
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(null),
        };
        vi.spyOn(document, 'createElement').mockReturnValue(canvas as unknown as HTMLCanvasElement);

        await expect(
            shareCardBlob({ kartu, theme: 'Cream', layout: 'poster', format: 'feed', showStats: false, showQuote: false }),
        ).rejects.toThrow('toBlob failed');
    });
});
