import { beforeEach, describe, expect, it, vi } from 'vitest';
import { drawRecapShare, recapShareBlob, type RecapFormat, type RecapShareData } from './recapShare';

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
        drawImage: vi.fn(),
        fillStyle: '',
        strokeStyle: '',
        lineWidth: 0,
        font: '',
        textAlign: '',
        textBaseline: '',
        letterSpacing: '',
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

describe('drawRecapShare', () => {
    const formats: RecapFormat[] = ['story', 'feed'];

    it.each(formats)('paints the %s format at its fixed resolution', async (format) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawRecapShare(canvas, recap, format);
        expect(canvas.width).toBe(1080);
        expect(canvas.height).toBe(format === 'story' ? 1920 : 1080);
        expect(ctx.fillRect).toHaveBeenCalled(); // background painted
        expect(ctx.fillText).toHaveBeenCalled(); // text drawn
        expect(ctx.drawImage).toHaveBeenCalled(); // brand bunny drawn
    });

    it('paints the dawn glow gradient', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawRecapShare(canvas, recap, 'story');
        expect(ctx.createRadialGradient).toHaveBeenCalled();
    });

    it('renders cleanly with a null delta, no streak, no best card, no goal', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawRecapShare(
            canvas,
            {
                ...recap,
                deltaPct: null,
                streakWeeks: 0,
                bestCardMove: null,
                bestCardRarity: null,
                nearestGoalTitle: null,
                nearestGoalRemainder: null,
            },
            'feed',
        );
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders a negative delta without throwing', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawRecapShare(canvas, { ...recap, deltaPct: -18 }, 'story');
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('does not throw when the 2d context is unavailable', async () => {
        const canvas = { width: 0, height: 0, getContext: () => null } as unknown as HTMLCanvasElement;
        await expect(drawRecapShare(canvas, recap, 'feed')).resolves.toBeUndefined();
    });
});

describe('recapShareBlob', () => {
    it('renders onto an offscreen canvas and resolves the PNG blob', async () => {
        const ctx = makeCtx();
        const blob = new Blob(['png'], { type: 'image/png' });
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(blob),
        } as unknown as HTMLCanvasElement;
        vi.spyOn(document, 'createElement').mockReturnValueOnce(canvas as unknown as HTMLElement);
        await expect(recapShareBlob(recap, 'story')).resolves.toBe(blob);
    });

    it('rejects when toBlob yields null', async () => {
        const ctx = makeCtx();
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(null),
        } as unknown as HTMLCanvasElement;
        vi.spyOn(document, 'createElement').mockReturnValueOnce(canvas as unknown as HTMLElement);
        await expect(recapShareBlob(recap, 'feed')).rejects.toThrow('toBlob failed');
    });
});
